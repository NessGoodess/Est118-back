<?php

namespace Tests\Feature\Admission;

use App\Enums\DocumentsStatus;
use App\Enums\PaymentStatus;
use App\Enums\PreEnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\AdmissionConversionBatch;
use App\Models\ClassGroup;
use App\Models\GradeLevel;
use App\Models\PreEnrollment;
use App\Models\Student;
use App\Models\User;
use App\Models\Profile;
use App\Enums\EnrollmentStatus;
use App\Models\Enrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AdmissionConversionBatchTest extends TestCase
{
    use RefreshDatabase;

    public function test_batch_handles_mixed_outcomes_and_persists(): void
    {
        $user = $this->userWithPermissions('edit admission enrollment');
        Sanctum::actingAs($user);

        [$year] = $this->seedYearWithGroup();
        $cycle = \App\Models\Admission\AdmissionCycle::factory()->active()->create();

        $ready = PreEnrollment::factory()->create([
            'admission_cycle_id' => $cycle->id,
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
        ]);

        [$existingStudent] = $this->createStudent($year->id);
        $already = PreEnrollment::factory()->create([
            'admission_cycle_id' => $cycle->id,
            'status' => PreEnrollmentStatus::APPROVED,
            'converted_student_id' => $existingStudent->id,
        ]);

        $rejected = PreEnrollment::factory()->create([
            'admission_cycle_id' => $cycle->id,
            'status' => PreEnrollmentStatus::REJECTED,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
        ]);

        $response = $this->postJson('/api/admissions/conversion-batches', [
            'pre_enrollment_ids' => [$ready->id, $already->id, $rejected->id, 999999],
            'academic_year_id' => $year->id,
            'expected_count' => 4,
        ], [
            'Idempotency-Key' => 'batch-key-mixed-001',
        ]);

        $response->assertOk()
            ->assertJsonPath('data.converted', 1)
            ->assertJsonPath('data.skipped', 1)
            ->assertJsonPath('data.failed', 2)
            ->assertJsonPath('data.status', 'completed');

        $batchId = $response->json('data.id');
        $this->assertDatabaseHas('admission_conversion_batches', [
            'id' => $batchId,
            'requested_count' => 4,
        ]);
        $this->assertDatabaseCount('admission_conversion_batch_items', 4);

        $this->getJson("/api/admissions/conversion-batches/{$batchId}")
            ->assertOk()
            ->assertJsonPath('data.id', $batchId);
    }

    public function test_batch_replays_same_idempotency_key(): void
    {
        $user = $this->userWithPermissions('edit admission enrollment');
        Sanctum::actingAs($user);

        [$year] = $this->seedYearWithGroup();
        $ready = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
        ]);

        $body = [
            'pre_enrollment_ids' => [$ready->id],
            'academic_year_id' => $year->id,
        ];
        $headers = ['Idempotency-Key' => 'batch-replay-001'];

        $first = $this->postJson('/api/admissions/conversion-batches', $body, $headers);
        $first->assertOk();
        $batchId = $first->json('data.id');

        $second = $this->postJson('/api/admissions/conversion-batches', $body, $headers);
        $second->assertOk()
            ->assertJsonPath('data.id', $batchId);

        $this->assertDatabaseCount('admission_conversion_batches', 1);
        $this->assertDatabaseCount('students', 1);
    }

    public function test_batch_same_key_different_body_returns_409(): void
    {
        $user = $this->userWithPermissions('edit admission enrollment');
        Sanctum::actingAs($user);

        [$year] = $this->seedYearWithGroup();
        $cycle = \App\Models\Admission\AdmissionCycle::factory()->active()->create();

        $a = PreEnrollment::factory()->create([
            'admission_cycle_id' => $cycle->id,
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
        ]);
        $b = PreEnrollment::factory()->create([
            'admission_cycle_id' => $cycle->id,
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
        ]);

        $headers = ['Idempotency-Key' => 'batch-conflict-001'];

        $this->postJson('/api/admissions/conversion-batches', [
            'pre_enrollment_ids' => [$a->id],
            'academic_year_id' => $year->id,
        ], $headers)->assertOk();

        $this->postJson('/api/admissions/conversion-batches', [
            'pre_enrollment_ids' => [$b->id],
            'academic_year_id' => $year->id,
        ], $headers)
            ->assertStatus(409)
            ->assertJsonPath('error_code', 'idempotency_key_reuse');
    }

    public function test_retry_failed_only_reprocesses_failures(): void
    {
        $user = $this->userWithPermissions('edit admission enrollment');
        Sanctum::actingAs($user);

        [$year] = $this->seedYearWithGroup();
        $cycle = \App\Models\Admission\AdmissionCycle::factory()->active()->create();

        $readyLater = PreEnrollment::factory()->create([
            'admission_cycle_id' => $cycle->id,
            'status' => PreEnrollmentStatus::PENDING,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
        ]);

        $ok = PreEnrollment::factory()->create([
            'admission_cycle_id' => $cycle->id,
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
        ]);

        $create = $this->postJson('/api/admissions/conversion-batches', [
            'pre_enrollment_ids' => [$readyLater->id, $ok->id],
            'academic_year_id' => $year->id,
        ], ['Idempotency-Key' => 'batch-retry-001']);

        $create->assertOk()->assertJsonPath('data.failed', 1)->assertJsonPath('data.converted', 1);
        $batchId = $create->json('data.id');

        $readyLater->update(['status' => PreEnrollmentStatus::IN_REVIEW]);

        $retry = $this->postJson("/api/admissions/conversion-batches/{$batchId}/retry-failed");
        $retry->assertOk()
            ->assertJsonPath('data.failed', 0)
            ->assertJsonPath('data.converted', 2);

        $this->assertDatabaseCount('students', 2);
    }

    public function test_expected_count_mismatch_returns_422(): void
    {
        $user = $this->userWithPermissions('edit admission enrollment');
        Sanctum::actingAs($user);

        [$year] = $this->seedYearWithGroup();
        $ready = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
        ]);

        $this->postJson('/api/admissions/conversion-batches', [
            'pre_enrollment_ids' => [$ready->id],
            'academic_year_id' => $year->id,
            'expected_count' => 5,
        ])->assertStatus(422)
            ->assertJsonPath('error_code', 'expected_count_mismatch');
    }

    /**
     * @return array{0: AcademicYear, 1: ClassGroup}
     */
    private function seedYearWithGroup(): array
    {
        $year = AcademicYear::factory()->create(['is_active' => true]);
        $grade = GradeLevel::create(['name' => '1°']);
        $group = ClassGroup::create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'name' => 'A',
        ]);

        return [$year, $group];
    }

    /**
     * @return array{0: Student, 1: Enrollment}
     */
    private function createStudent(int $academicYearId): array
    {
        $year = AcademicYear::findOrFail($academicYearId);
        $grade = GradeLevel::query()->where('name', '1°')->firstOrFail();
        $group = ClassGroup::query()
            ->where('academic_year_id', $year->id)
            ->where('grade_level_id', $grade->id)
            ->firstOrFail();

        $profile = Profile::create([
            'national_id' => 'TEST'.fake()->unique()->numerify('############'),
            'first_name' => 'Exist',
            'last_name' => 'Student',
            'gender' => 'O',
        ]);
        $student = Student::create(['profile_id' => $profile->id]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'class_group_id' => $group->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
            'is_new_admission' => true,
        ]);

        return [$student, $enrollment];
    }

    private function userWithPermissions(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        return $user;
    }
}
