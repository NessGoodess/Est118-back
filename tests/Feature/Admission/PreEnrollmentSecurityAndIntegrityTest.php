<?php

namespace Tests\Feature\Admission;

use App\Enums\DocumentsStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\PreEnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Address;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\PreEnrollment;
use App\Models\Profile;
use App\Models\Student;
use App\Models\User;
use App\Services\ConvertPreEnrollmentToStudentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PreEnrollmentSecurityAndIntegrityTest extends TestCase
{
    use RefreshDatabase;

    public function test_show_hides_ip_user_agent_and_document_paths(): void
    {
        $user = $this->userWithPermissions('view pre-enrollments');
        Sanctum::actingAs($user);

        $preEnrollment = PreEnrollment::factory()->create([
            'ip_address' => '203.0.113.10',
            'user_agent' => 'SecretAgent/1.0',
            'birth_certificate_path' => 'private/docs/birth.pdf',
            'curp_document_path' => 'private/docs/curp.pdf',
            'address_proof_path' => 'private/docs/address.pdf',
            'study_certificate_path' => 'private/docs/study.pdf',
            'photo_path' => 'private/docs/photo.jpg',
            'unit_number' => 'Int-12',
        ]);

        $response = $this->getJson("/api/admissions/pre-enrollments/{$preEnrollment->id}");

        $response->assertOk()
            ->assertJsonPath('id', $preEnrollment->id)
            ->assertJsonPath('unit_number', 'Int-12')
            ->assertJsonMissingPath('ip_address')
            ->assertJsonMissingPath('user_agent')
            ->assertJsonMissingPath('birth_certificate_path')
            ->assertJsonMissingPath('curp_document_path')
            ->assertJsonMissingPath('address_proof_path')
            ->assertJsonMissingPath('study_certificate_path')
            ->assertJsonMissingPath('photo_path');
    }

    public function test_export_requires_view_pre_enrollments_permission(): void
    {
        $unauthorized = User::factory()->create();
        Sanctum::actingAs($unauthorized);

        $this->get('/api/admissions/pre-enrollments/export')
            ->assertForbidden();

        $authorized = $this->userWithPermissions('view pre-enrollments');
        Sanctum::actingAs($authorized);

        $this->get('/api/admissions/pre-enrollments/export')
            ->assertOk();
    }

    public function test_process_and_update_are_blocked_when_already_converted(): void
    {
        $user = $this->userWithPermissions(
            'view pre-enrollments',
            'edit pre-enrollments',
            'edit admission enrollment',
        );
        Sanctum::actingAs($user);

        [$student] = $this->createStudentAndEnrollment();
        $preEnrollment = PreEnrollment::factory()->create([
            'converted_student_id' => $student->id,
            'status' => PreEnrollmentStatus::APPROVED,
        ]);

        $this->patchJson("/api/admissions/pre-enrollments/{$preEnrollment->id}/process", [
            'status' => PreEnrollmentStatus::REJECTED->value,
        ])->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->patchJson("/api/admissions/pre-enrollments/{$preEnrollment->id}", [
            'phone' => '9511111111',
        ])->assertStatus(422)
            ->assertJsonPath('success', false);

        $this->assertSame(
            PreEnrollmentStatus::APPROVED,
            $preEnrollment->fresh()->status
        );
        $this->assertNotSame('9511111111', $preEnrollment->fresh()->phone);
    }

    public function test_conversion_preserves_unit_number_on_address(): void
    {
        $academicYear = AcademicYear::factory()->create(['is_active' => true]);
        $grade = GradeLevel::create(['name' => '1°']);
        ClassGroup::create([
            'academic_year_id' => $academicYear->id,
            'grade_level_id' => $grade->id,
            'name' => 'A',
        ]);

        $preEnrollment = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
            'unit_number' => 'Dep-8B',
        ]);

        $result = app(ConvertPreEnrollmentToStudentService::class)
            ->convert($preEnrollment, ['academic_year_id' => $academicYear->id]);

        $addressId = $result['student']->profile->address_id;
        $this->assertNotNull($addressId);
        $this->assertDatabaseHas('addresses', [
            'id' => $addressId,
            'unit_number' => 'Dep-8B',
        ]);
        $this->assertSame('Dep-8B', Address::find($addressId)?->unit_number);
    }

    /**
     * @return array{Student, Enrollment}
     */
    private function createStudentAndEnrollment(?int $academicYearId = null): array
    {
        $academicYear = $academicYearId
            ? AcademicYear::findOrFail($academicYearId)
            : AcademicYear::factory()->create();

        $grade = GradeLevel::query()->where('name', '1°')->first()
            ?? GradeLevel::create(['name' => '1°']);

        $group = ClassGroup::query()
            ->where('academic_year_id', $academicYear->id)
            ->where('grade_level_id', $grade->id)
            ->first()
            ?? ClassGroup::create([
                'academic_year_id' => $academicYear->id,
                'grade_level_id' => $grade->id,
                'name' => 'A',
            ]);

        $profile = Profile::create([
            'national_id' => 'TEST'.fake()->unique()->numerify('############'),
            'first_name' => 'Replay',
            'last_name' => 'Student',
            'gender' => 'O',
        ]);
        $student = Student::create(['profile_id' => $profile->id]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'class_group_id' => $group->id,
            'academic_year_id' => $academicYear->id,
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
