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

class ReEnrollmentAdmissionCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_admission_promotion_bypass_routes_no_longer_exist(): void
    {
        $user = $this->userWithPermissions('manage admission cycles', 'manage re-enrollment');
        Sanctum::actingAs($user);

        $this->getJson('/api/admissions/enrollments/pending-decisions')->assertNotFound();
        $this->patchJson('/api/admissions/enrollments/1/promotion-decision', [
            'is_approved' => true,
        ])->assertNotFound();
    }

    public function test_academic_year_promote_route_still_exists(): void
    {
        $user = $this->userWithPermissions('manage re-enrollment');
        Sanctum::actingAs($user);

        $this->postJson('/api/academic-years/promote', [])->assertStatus(422);
    }

    public function test_sync_applications_creates_request_for_late_enrollment(): void
    {
        [$user, $period] = $this->openPeriodWithOnePending();
        $late = $this->makeLateEnrollment($period);
        Sanctum::actingAs($user);

        $this->getJson("/api/school/re-enrollment/periods/{$period->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.unsynced_enrollments', 1)
            ->assertJsonPath('data.missing_enrollment_decisions', 2);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/sync-applications")
            ->assertOk()
            ->assertJsonPath('data.created', 1);

        $this->assertDatabaseHas('re_enrollment_applications', [
            're_enrollment_period_id' => $period->id,
            'enrollment_id' => $late->id,
            'status' => ReEnrollmentValidationStatus::PENDING->value,
        ]);

        $this->assertSame(2, $period->applications()->count());

        $this->getJson("/api/school/re-enrollment/periods/{$period->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.unsynced_enrollments', 0)
            ->assertJsonPath('data.pending', 2);
    }

    public function test_sync_applications_does_not_delete_existing_requests(): void
    {
        [$user, $period, $pending] = $this->openPeriodWithOnePending();
        Sanctum::actingAs($user);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/sync-applications")
            ->assertOk()
            ->assertJsonPath('data.created', 0);

        $this->assertTrue($pending->fresh()->exists);
        $this->assertSame(1, $period->applications()->count());
    }

    public function test_promote_syncs_late_enrollment_instead_of_failing_as_unknown(): void
    {
        [$user, $period, $pending] = $this->openPeriodWithOnePending();
        $this->markAdminValidated($pending);
        Sanctum::actingAs($user);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/bulk-decide", [
            'ids' => [$pending->id],
            'is_approved' => true,
        ])->assertOk();

        $period->update(['current_step' => ReEnrollmentProcessStep::PROMOTION]);
        $late = $this->makeLateEnrollment($period);

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/promote", [
            'dry_run' => true,
        ])->assertStatus(422);

        $lateApp = ReEnrollmentApplication::query()
            ->where('re_enrollment_period_id', $period->id)
            ->where('enrollment_id', $late->id)
            ->first();

        $this->assertNotNull($lateApp);
        $this->assertSame(ReEnrollmentValidationStatus::PENDING, $lateApp->status);

        $this->markAdminValidated($lateApp);
        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/applications/bulk-decide", [
            'ids' => [$lateApp->id],
            'is_approved' => true,
        ])->assertOk();

        $this->postJson("/api/school/re-enrollment/periods/{$period->id}/promote", [
            'dry_run' => true,
        ])->assertOk();
    }

    /**
     * @return array{0: User, 1: ReEnrollmentPeriod, 2: ReEnrollmentApplication}
     */
    private function openPeriodWithOnePending(): array
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

        return [$user, $period, $pending];
    }

    private function makeLateEnrollment(ReEnrollmentPeriod $period): Enrollment
    {
        $group = ClassGroup::query()
            ->where('academic_year_id', $period->from_academic_year_id)
            ->firstOrFail();

        $profile = Profile::create([
            'national_id' => 'TEST'.fake()->unique()->numerify('############'),
            'first_name' => 'Tardio',
            'last_name' => 'Nuevo',
            'gender' => 'O',
        ]);
        $student = Student::create(['profile_id' => $profile->id]);

        return Enrollment::create([
            'student_id' => $student->id,
            'class_group_id' => $group->id,
            'academic_year_id' => $period->from_academic_year_id,
            'status' => EnrollmentStatus::Active,
            'is_approved' => null,
        ]);
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
