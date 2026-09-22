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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReEnrollmentBulkValidateTest extends TestCase
{
    use RefreshDatabase;

    public function test_bulk_validate_marks_debts_and_data_without_approving(): void
    {
        [$user, $period, $pending, $rejected] = $this->openPeriodWithApplications();
        Sanctum::actingAs($user);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/bulk-validate", [
            'scopes' => ['debts', 'data_update'],
        ])
            ->assertOk()
            ->assertJsonPath('data.updated', 1)
            ->assertJsonPath('data.newly_validated', 0);

        $pending->refresh();
        $rejected->refresh();

        $this->assertTrue($pending->no_debts);
        $this->assertTrue($pending->guardian_updated);
        $this->assertTrue($pending->phone_updated);
        $this->assertTrue($pending->address_updated);
        $this->assertTrue($pending->photo_updated);
        $this->assertNull($pending->passed_cycle);
        $this->assertSame(ReEnrollmentValidationStatus::IN_REVIEW, $pending->status);

        $this->assertNull($rejected->no_debts);
        $this->assertSame(ReEnrollmentValidationStatus::REJECTED, $rejected->status);
    }

    public function test_bulk_validate_respects_grade_filter(): void
    {
        [$user, $period, $first, $second] = $this->openPeriodWithTwoGrades();
        Sanctum::actingAs($user);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/bulk-validate", [
            'scopes' => ['debts'],
            'grade' => '1°',
        ])
            ->assertOk()
            ->assertJsonPath('data.updated', 1);

        $this->assertTrue($first->fresh()->no_debts);
        $this->assertNull($second->fresh()->no_debts);
    }

    public function test_bulk_validate_admin_checks_marks_validated_without_grade_decision(): void
    {
        [$user, $period, $pending] = $this->openPeriodWithApplications();
        Sanctum::actingAs($user);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/bulk-validate", [
            'scopes' => ['debts', 'data_update', 'documents'],
        ])
            ->assertOk()
            ->assertJsonPath('data.newly_validated', 1);

        $pending->refresh();
        $this->assertSame(ReEnrollmentValidationStatus::VALIDATED, $pending->status);
        $this->assertNull($pending->passed_cycle);
        $this->assertNull($pending->enrollment->fresh()->is_approved);
    }

    public function test_reject_marks_application_and_clears_enrollment_decision(): void
    {
        [$user, $period, $pending] = $this->openPeriodWithApplications();
        Sanctum::actingAs($user);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/bulk-decide", [
            'ids' => [$pending->id],
            'reject' => true,
        ])->assertOk();

        $pending->refresh();
        $this->assertSame(ReEnrollmentValidationStatus::REJECTED, $pending->status);
        $this->assertFalse($pending->passed_cycle);
        $this->assertFalse($pending->enrollment->fresh()->is_approved);
    }

    public function test_bulk_decide_requires_admin_validation_first(): void
    {
        [$user, $period, $pending] = $this->openPeriodWithApplications();
        Sanctum::actingAs($user);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/bulk-decide", [
            'ids' => [$pending->id],
            'is_approved' => true,
        ])->assertStatus(422);
    }

    public function test_bulk_decide_approves_validated_students(): void
    {
        [$user, $period, $pending] = $this->openPeriodWithApplications();
        $this->markAdminValidated($pending);
        Sanctum::actingAs($user);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/bulk-decide", [
            'ids' => [$pending->id],
            'is_approved' => true,
        ])->assertOk();

        $pending->refresh();
        $this->assertTrue($pending->passed_cycle);
        $this->assertTrue($pending->enrollment->fresh()->is_approved);
        $this->assertSame(ReEnrollmentValidationStatus::VALIDATED, $pending->status);
    }

    public function test_cannot_promote_until_grade_decisions_are_captured(): void
    {
        [$user, $period, $pending] = $this->openPeriodWithApplications();
        $this->markAdminValidated($pending);
        $period->update(['current_step' => ReEnrollmentProcessStep::PROMOTION]);
        Sanctum::actingAs($user);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/promote", [
            'dry_run' => true,
        ])->assertStatus(422);
    }

    public function test_closed_period_cannot_bulk_validate(): void
    {
        [$user, $period] = $this->openPeriodWithApplications();
        $period->update(['status' => ReEnrollmentPeriodStatus::CLOSED]);
        Sanctum::actingAs($user);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/bulk-validate", [
            'scopes' => ['debts'],
        ])->assertStatus(422);
    }

    /**
     * @return array{0: User, 1: ReEnrollmentPeriod, 2: ReEnrollmentApplication, 3: ReEnrollmentApplication}
     */
    private function openPeriodWithApplications(): array
    {
        $user = $this->userWithPermissions('manage re-enrollment');
        $from = AcademicYear::factory()->create();
        $to = AcademicYear::factory()->create();
        $grade = GradeLevel::create(['name' => '1°']);
        $group = ClassGroup::create([
            'academic_year_id' => $from->id,
            'grade_level_id' => $grade->id,
            'name' => 'A',
        ]);

        $period = ReEnrollmentPeriod::create([
            'name' => 'Reinscripcion test',
            'from_academic_year_id' => $from->id,
            'to_academic_year_id' => $to->id,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
            'status' => ReEnrollmentPeriodStatus::OPEN,
            'current_step' => ReEnrollmentProcessStep::VALIDATION,
            'keep_current_groups' => true,
            'created_by' => $user->id,
        ]);

        $pending = $this->makeApplication($period, $from, $group, 'Ana', 'Pendiente');
        $rejected = $this->makeApplication($period, $from, $group, 'Luis', 'Rechazado', ReEnrollmentValidationStatus::REJECTED);

        return [$user, $period, $pending, $rejected];
    }

    /**
     * @return array{0: User, 1: ReEnrollmentPeriod, 2: ReEnrollmentApplication, 3: ReEnrollmentApplication}
     */
    private function openPeriodWithTwoGrades(): array
    {
        $user = $this->userWithPermissions('manage re-enrollment');
        $from = AcademicYear::factory()->create();
        $to = AcademicYear::factory()->create();
        $firstGrade = GradeLevel::create(['name' => '1°']);
        $secondGrade = GradeLevel::create(['name' => '2°']);
        $firstGroup = ClassGroup::create([
            'academic_year_id' => $from->id,
            'grade_level_id' => $firstGrade->id,
            'name' => 'A',
        ]);
        $secondGroup = ClassGroup::create([
            'academic_year_id' => $from->id,
            'grade_level_id' => $secondGrade->id,
            'name' => 'A',
        ]);

        $period = ReEnrollmentPeriod::create([
            'name' => 'Reinscripcion test',
            'from_academic_year_id' => $from->id,
            'to_academic_year_id' => $to->id,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
            'status' => ReEnrollmentPeriodStatus::OPEN,
            'current_step' => ReEnrollmentProcessStep::VALIDATION,
            'keep_current_groups' => true,
            'created_by' => $user->id,
        ]);

        $first = $this->makeApplication($period, $from, $firstGroup, 'Ana', 'Primero');
        $second = $this->makeApplication($period, $from, $secondGroup, 'Luis', 'Segundo');

        return [$user, $period, $first, $second];
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
        string $lastName,
        ReEnrollmentValidationStatus $status = ReEnrollmentValidationStatus::PENDING
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
            'is_approved' => $status === ReEnrollmentValidationStatus::REJECTED ? false : null,
        ]);

        return ReEnrollmentApplication::create([
            're_enrollment_period_id' => $period->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'status' => $status,
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
