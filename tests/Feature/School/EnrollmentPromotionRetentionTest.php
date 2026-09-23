<?php

namespace Tests\Feature\School;

use App\Enums\EnrollmentStatus;
use App\Enums\PromotionResult;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Profile;
use App\Models\Student;
use App\Services\EnrollmentPromotionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EnrollmentPromotionRetentionTest extends TestCase
{
    use RefreshDatabase;

    public function test_failed_third_grader_repeats_in_destination_third_grade(): void
    {
        $from = AcademicYear::factory()->create();
        $to = AcademicYear::factory()->create();
        $third = GradeLevel::create(['name' => '3°']);
        $fromGroup = ClassGroup::create([
            'academic_year_id' => $from->id,
            'grade_level_id' => $third->id,
            'name' => 'A',
        ]);

        $student = $this->makeStudent('Rosa', 'Lopez');
        Enrollment::create([
            'student_id' => $student->id,
            'class_group_id' => $fromGroup->id,
            'academic_year_id' => $from->id,
            'status' => EnrollmentStatus::Active,
            'is_approved' => false,
        ]);

        $summary = app(EnrollmentPromotionService::class)->promote($from->id, $to->id, false);

        $this->assertSame(1, $summary['retained']);
        $this->assertSame(0, $summary['graduated']);
        $this->assertSame([], $summary['errors']);

        $destGroup = ClassGroup::query()
            ->where('academic_year_id', $to->id)
            ->where('name', 'A')
            ->where('grade_level_id', $third->id)
            ->first();

        $this->assertNotNull($destGroup);

        $destEnrollment = Enrollment::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', $to->id)
            ->first();

        $this->assertNotNull($destEnrollment);
        $this->assertSame($destGroup->id, $destEnrollment->class_group_id);
        $this->assertSame(PromotionResult::RETAINED, $destEnrollment->promotion_result);
        $this->assertSame(
            EnrollmentStatus::Completed,
            Enrollment::query()->where('academic_year_id', $from->id)->first()?->status
        );
    }

    public function test_approved_second_grader_moves_to_third(): void
    {
        $from = AcademicYear::factory()->create();
        $to = AcademicYear::factory()->create();
        $second = GradeLevel::create(['name' => '2°']);
        GradeLevel::create(['name' => '3°']);
        $fromGroup = ClassGroup::create([
            'academic_year_id' => $from->id,
            'grade_level_id' => $second->id,
            'name' => 'B',
        ]);

        $student = $this->makeStudent('Marco', 'Diaz');
        Enrollment::create([
            'student_id' => $student->id,
            'class_group_id' => $fromGroup->id,
            'academic_year_id' => $from->id,
            'status' => EnrollmentStatus::Active,
            'is_approved' => true,
        ]);

        $summary = app(EnrollmentPromotionService::class)->promote($from->id, $to->id, false);

        $this->assertSame(1, $summary['promoted']);
        $this->assertSame([], $summary['errors']);

        $destEnrollment = Enrollment::query()
            ->with('classGroup.gradeLevel')
            ->where('student_id', $student->id)
            ->where('academic_year_id', $to->id)
            ->first();

        $this->assertNotNull($destEnrollment);
        $this->assertSame('3°', $destEnrollment->classGroup?->gradeLevel?->name);
        $this->assertSame('B', $destEnrollment->classGroup?->name);
    }

    private function makeStudent(string $firstName, string $lastName): Student
    {
        $profile = Profile::create([
            'national_id' => 'TEST'.fake()->unique()->numerify('############'),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => 'O',
        ]);

        return Student::create(['profile_id' => $profile->id]);
    }
}
