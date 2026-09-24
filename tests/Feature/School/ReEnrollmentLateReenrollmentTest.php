<?php

namespace Tests\Feature\School;

use App\Enums\EnrollmentStatus;
use App\Enums\ReEnrollmentPeriodStatus;
use App\Enums\ReEnrollmentProcessStep;
use App\Enums\ReEnrollmentValidationStatus;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Profile;
use App\Models\School\ReEnrollmentApplication;
use App\Models\School\ReEnrollmentPeriod;
use App\Models\Student;
use App\Models\User;
use App\Services\CredentialPrintingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReEnrollmentLateReenrollmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_batch_puts_pending_student_in_waiting_and_excludes_from_credentials(): void
    {
        [$user, $period, $validated, $pending] = $this->openPeriodWithTwoStudents();
        $this->markAdminValidated($validated);
        Sanctum::actingAs($user);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/bulk-decide", [
            'ids' => [$validated->id, $pending->id],
            'is_approved' => true,
        ])->assertOk();

        $period->update(['current_step' => ReEnrollmentProcessStep::PROMOTION]);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/promote")
            ->assertOk()
            ->assertJsonPath('data.processed', 2)
            ->assertJsonPath('data.skipped_without_decision', 0);

        $this->getJson("/api/school/re-enrollment/periods/{$period->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.can_validate', true)
            ->assertJsonPath('data.waiting_activation', 1);

        $validatedDest = $this->destinationEnrollment($period, $validated);
        $pendingDest = $this->destinationEnrollment($period, $pending);

        $this->assertSame(EnrollmentStatus::Active, $validatedDest?->status);
        $this->assertSame(EnrollmentStatus::PreEnrolled, $pendingDest?->status);

        $rows = app(CredentialPrintingService::class)->rowsForClassGroup($pendingDest->classGroup);
        $ids = collect($rows['rows'])->pluck('student_id');

        $this->assertTrue($ids->contains($validated->student_id));
        $this->assertFalse($ids->contains($pending->student_id));
    }

    public function test_confirming_presence_activates_waiting_student_for_credentials(): void
    {
        [$user, $period, $validated, $pending] = $this->openPeriodWithTwoStudents();
        $this->markAdminValidated($validated);
        Sanctum::actingAs($user);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/bulk-decide", [
            'ids' => [$validated->id, $pending->id],
            'is_approved' => true,
        ])->assertOk();
        $period->update(['current_step' => ReEnrollmentProcessStep::PROMOTION]);
        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/promote")->assertOk();

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/{$pending->id}/confirm-presence")
            ->assertOk()
            ->assertJsonPath('data.destination_status', 'active');

        $pendingDest = $this->destinationEnrollment($period, $pending);
        $this->assertSame(EnrollmentStatus::Active, $pendingDest?->status);

        $rows = app(CredentialPrintingService::class)->rowsForClassGroup($pendingDest->classGroup);
        $this->assertTrue(collect($rows['rows'])->pluck('student_id')->contains($pending->student_id));
    }

    public function test_completing_checklist_after_promote_also_activates_waiting_destination(): void
    {
        [$user, $period, $validated, $pending] = $this->openPeriodWithTwoStudents();
        $this->markAdminValidated($validated);
        Sanctum::actingAs($user);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/bulk-decide", [
            'ids' => [$pending->id],
            'is_approved' => true,
        ])->assertOk();
        $period->update(['current_step' => ReEnrollmentProcessStep::PROMOTION]);
        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/promote")->assertOk();

        $this->assertSame(
            EnrollmentStatus::PreEnrolled,
            $this->destinationEnrollment($period, $pending)?->status
        );

        $this->patchJson("/api/school/re-enrollment/periods/{$period->id}/applications/{$pending->id}", [
            'documents_complete' => true,
            'guardian_updated' => true,
            'phone_updated' => true,
            'address_updated' => true,
            'photo_updated' => true,
            'no_debts' => true,
            'status' => ReEnrollmentValidationStatus::IN_REVIEW->value,
        ])->assertOk();

        $this->assertSame(
            EnrollmentStatus::Active,
            $this->destinationEnrollment($period, $pending)?->status
        );
    }

    public function test_confirm_dropout_drops_waiting_destination_and_does_not_create_repeater(): void
    {
        [$user, $period, $validated, $pending] = $this->openPeriodWithTwoStudents();
        Sanctum::actingAs($user);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/bulk-decide", [
            'ids' => [$pending->id],
            'is_approved' => true,
        ])->assertOk();
        $period->update(['current_step' => ReEnrollmentProcessStep::PROMOTION]);
        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/promote")->assertOk();

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/{$pending->id}/confirm-dropout")
            ->assertOk();

        $pending->refresh();
        $this->assertSame(ReEnrollmentValidationStatus::REJECTED, $pending->status);
        $this->assertTrue($pending->passed_cycle);
        $this->assertTrue((bool) $pending->enrollment->fresh()->is_approved);

        $dest = $this->destinationEnrollment($period, $pending);
        $this->assertSame(EnrollmentStatus::Dropped, $dest?->status);
        $this->assertSame(EnrollmentStatus::Completed, $pending->enrollment->fresh()->status);

        $repeaters = Enrollment::query()
            ->where('student_id', $pending->student_id)
            ->where('academic_year_id', $period->to_academic_year_id)
            ->where('status', '!=', EnrollmentStatus::Dropped->value)
            ->count();
        $this->assertSame(0, $repeaters);
    }

    public function test_late_placement_creates_destination_for_student_decided_after_execute(): void
    {
        [$user, $period, $validated, $pending] = $this->openPeriodWithTwoStudents();
        $this->markAdminValidated($validated);
        Sanctum::actingAs($user);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/bulk-decide", [
            'ids' => [$validated->id],
            'is_approved' => true,
        ])->assertOk();
        $period->update(['current_step' => ReEnrollmentProcessStep::PROMOTION]);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/promote")
            ->assertOk()
            ->assertJsonPath('data.processed', 1)
            ->assertJsonPath('data.skipped_without_decision', 1);

        $this->assertNull($this->destinationEnrollment($period, $pending));
        $this->assertSame(EnrollmentStatus::Active, $pending->enrollment->fresh()->status);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/bulk-decide", [
            'ids' => [$pending->id],
            'is_approved' => true,
        ])->assertOk();

        $this->getJson("/api/school/re-enrollment/periods/{$period->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.can_place_late', true);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/promote")
            ->assertOk()
            ->assertJsonPath('data.processed', 1);

        $this->assertSame(
            EnrollmentStatus::PreEnrolled,
            $this->destinationEnrollment($period, $pending)?->status
        );
        $this->assertSame(EnrollmentStatus::Completed, $pending->enrollment->fresh()->status);
    }

    public function test_validation_stays_open_after_promotion(): void
    {
        [$user, $period, $validated] = $this->openPeriodWithTwoStudents();
        $this->markAdminValidated($validated);
        Sanctum::actingAs($user);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/bulk-decide", [
            'ids' => [$validated->id],
            'is_approved' => true,
        ])->assertOk();
        $period->update(['current_step' => ReEnrollmentProcessStep::PROMOTION]);
        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/promote")->assertOk();

        $this->patchJson("/api/school/re-enrollment/periods/{$period->id}/applications/{$validated->id}", [
            'comments' => 'Llego a recoger papeles',
        ])->assertOk();
    }

    /**
     * @return array{0: User, 1: ReEnrollmentPeriod, 2: ReEnrollmentApplication, 3: ReEnrollmentApplication}
     */
    private function openPeriodWithTwoStudents(): array
    {
        $user = $this->userWithPermissions('manage re-enrollment');
        $from = AcademicYear::factory()->create();
        $to = AcademicYear::factory()->create();
        $first = GradeLevel::firstOrCreate(['name' => '1°']);
        GradeLevel::firstOrCreate(['name' => '2°']);
        $group = ClassGroup::create([
            'academic_year_id' => $from->id,
            'grade_level_id' => $first->id,
            'name' => 'A',
        ]);

        $period = ReEnrollmentPeriod::create([
            'name' => 'Reinscripcion tardia',
            'from_academic_year_id' => $from->id,
            'to_academic_year_id' => $to->id,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
            'status' => ReEnrollmentPeriodStatus::OPEN,
            'current_step' => ReEnrollmentProcessStep::VALIDATION,
            'keep_current_groups' => true,
            'created_by' => $user->id,
        ]);

        $validated = $this->makeApplication($period, $from, $group, 'Ana', 'Lista');
        $pending = $this->makeApplication($period, $from, $group, 'Luis', 'Ausente');

        return [$user, $period, $validated, $pending];
    }

    private function destinationEnrollment(ReEnrollmentPeriod $period, ReEnrollmentApplication $application): ?Enrollment
    {
        return Enrollment::query()
            ->with('classGroup')
            ->where('student_id', $application->student_id)
            ->where('academic_year_id', $period->to_academic_year_id)
            ->latest('id')
            ->first();
    }

    private function markAdminValidated(ReEnrollmentApplication $application): void
    {
        $application->update([
            'documents_complete' => true,
            'guardian_updated' => true,
            'phone_updated' => true,
            'address_updated' => true,
            'photo_updated' => true,
            'no_debts' => true,
            'status' => ReEnrollmentValidationStatus::VALIDATED,
        ]);
    }

    private function makeApplication(
        ReEnrollmentPeriod $period,
        AcademicYear $year,
        ClassGroup $group,
        string $firstName,
        string $lastName
    ): ReEnrollmentApplication {
        $profile = Profile::create([
            'national_id' => 'TEST'.fake()->unique()->numerify('############'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => 'O',
        ]);
        $student = Student::create(['profile_id' => $profile->id]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'class_group_id' => $group->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
            'is_approved' => null,
        ]);

        return ReEnrollmentApplication::create([
            're_enrollment_period_id' => $period->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'status' => ReEnrollmentValidationStatus::PENDING,
        ]);
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
