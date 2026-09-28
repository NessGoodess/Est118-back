<?php

namespace Tests\Feature\School;

use App\Enums\EnrollmentStatus;
use App\Enums\PreEnrollmentStatus;
use App\Enums\ReEnrollmentPeriodStatus;
use App\Enums\ReEnrollmentProcessStep;
use App\Enums\ReEnrollmentValidationStatus;
use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\PreEnrollment;
use App\Models\Profile;
use App\Models\School\ReEnrollmentApplication;
use App\Models\School\ReEnrollmentPeriod;
use App\Models\Student;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopEnrollment;
use App\Services\School\ReEnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReEnrollmentIncomingIntakeExclusionTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_intake_leaves_promotion_and_pending_first_grader_stays(): void
    {
        $from = AcademicYear::factory()->create(['is_active' => true, 'year_start' => '2025', 'year_end' => '2026']);
        $to = AcademicYear::factory()->create(['is_active' => false, 'year_start' => '2026', 'year_end' => '2027']);
        $first = GradeLevel::query()->create(['name' => '1°']);
        GradeLevel::query()->create(['name' => '2°']);
        $originGroup = ClassGroup::query()->create([
            'academic_year_id' => $from->id,
            'grade_level_id' => $first->id,
            'name' => 'B',
        ]);
        $workshop = Workshop::query()->create([
            'code' => 'INFORMATICA',
            'name' => 'Informática',
            'is_active' => true,
        ]);
        $user = User::factory()->create();
        $period = ReEnrollmentPeriod::query()->create([
            'name' => '2026-2027',
            'from_academic_year_id' => $from->id,
            'to_academic_year_id' => $to->id,
            'status' => ReEnrollmentPeriodStatus::OPEN,
            'current_step' => ReEnrollmentProcessStep::PROMOTION,
            'keep_current_groups' => true,
            'created_by' => $user->id,
        ]);

        $pending = $this->enroll($from, $originGroup, 'PEND130101HOCRRNA1', 'RAUL', [
            'is_new_admission' => false,
            'admission_channel' => null,
        ]);
        ReEnrollmentApplication::query()->create([
            're_enrollment_period_id' => $period->id,
            'enrollment_id' => $pending->id,
            'student_id' => $pending->student_id,
            'status' => ReEnrollmentValidationStatus::VALIDATED,
            'passed_cycle' => null,
        ]);

        $lastYear = $this->enroll($from, $originGroup, 'CAMP130101HOCRRNA2', 'ANA', [
            'is_new_admission' => true,
            'admission_channel' => 'campaign',
        ]);
        ReEnrollmentApplication::query()->create([
            're_enrollment_period_id' => $period->id,
            'enrollment_id' => $lastYear->id,
            'student_id' => $lastYear->student_id,
            'status' => ReEnrollmentValidationStatus::VALIDATED,
            'passed_cycle' => true,
        ]);
        $lastYear->update(['is_approved' => true]);

        $intake = $this->enroll($from, $originGroup, 'LATE130101HOCRRNA3', 'MARIA', [
            'is_new_admission' => true,
            'admission_channel' => 'late',
        ]);
        ReEnrollmentApplication::query()->create([
            're_enrollment_period_id' => $period->id,
            'enrollment_id' => $intake->id,
            'student_id' => $intake->student_id,
            'status' => ReEnrollmentValidationStatus::PENDING,
        ]);
        PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::APPROVED,
            'converted_student_id' => $intake->student_id,
            'converted_enrollment_id' => $intake->id,
            'converted_at' => now(),
        ]);
        WorkshopEnrollment::query()->create([
            'student_id' => $intake->student_id,
            'workshop_id' => $workshop->id,
            'academic_year_id' => $from->id,
            'source' => WorkshopEnrollmentSource::Manual,
            'status' => WorkshopEnrollmentStatus::Assigned,
        ]);

        $manual = $this->enroll($from, $originGroup, 'MANU130101HOCRRNA4', 'LUIS', [
            'is_new_admission' => true,
            'admission_channel' => 'manual',
        ]);
        ReEnrollmentApplication::query()->create([
            're_enrollment_period_id' => $period->id,
            'enrollment_id' => $manual->id,
            'student_id' => $manual->student_id,
            'status' => ReEnrollmentValidationStatus::PENDING,
        ]);

        $service = app(ReEnrollmentService::class);
        $created = $service->syncApplications($period);
        $stats = $service->dashboardStats($period);

        $this->assertSame(0, $created);
        $this->assertSame(2, $stats['total_students']);
        $this->assertSame(1, $stats['pending_grade_decisions']);
        $this->assertSame(1, $stats['ready_for_promotion']);
        $this->assertFalse(
            ReEnrollmentApplication::query()
                ->where('re_enrollment_period_id', $period->id)
                ->whereIn('student_id', [$intake->student_id, $manual->student_id])
                ->exists()
        );
        $this->assertTrue(
            ReEnrollmentApplication::query()
                ->where('re_enrollment_period_id', $period->id)
                ->where('student_id', $pending->student_id)
                ->exists()
        );

        $intake->refresh();
        $this->assertSame($to->id, $intake->academic_year_id);
        $this->assertSame(EnrollmentStatus::Active, $intake->status);
        $this->assertSame($first->id, $intake->classGroup->grade_level_id);
        $this->assertSame('B', $intake->classGroup->name);
        $this->assertSame(
            $to->id,
            WorkshopEnrollment::query()->where('student_id', $intake->student_id)->value('academic_year_id')
        );

        $manual->refresh();
        $this->assertSame($to->id, $manual->academic_year_id);

        $pending->refresh();
        $this->assertSame($from->id, $pending->academic_year_id);
        $this->assertNull($pending->is_approved);

        $summary = $service->promotePeriod($period, dryRun: false);
        $this->assertSame(1, $summary['processed']);
        $this->assertSame(1, $summary['promoted']);
        $this->assertSame(EnrollmentStatus::Active, $pending->fresh()->status);
        $this->assertNull(Enrollment::query()->where('student_id', $pending->student_id)->where('academic_year_id', $to->id)->first());
        $this->assertSame($to->id, $intake->fresh()->academic_year_id);
        $this->assertSame(EnrollmentStatus::Active, $intake->fresh()->status);
    }

    /**
     * @param  array{is_new_admission: bool, admission_channel: ?string}  $options
     */
    private function enroll(AcademicYear $year, ClassGroup $group, string $curp, string $firstName, array $options): Enrollment
    {
        $profile = Profile::query()->create([
            'national_id' => $curp,
            'first_name' => $firstName,
            'last_name' => 'PRUEBA',
            'gender' => 'M',
            'birth_date' => '2013-01-01',
        ]);
        $student = Student::query()->create(['profile_id' => $profile->id]);

        return Enrollment::query()->create([
            'student_id' => $student->id,
            'class_group_id' => $group->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
            'is_new_admission' => $options['is_new_admission'],
            'admission_channel' => $options['admission_channel'],
        ]);
    }
}
