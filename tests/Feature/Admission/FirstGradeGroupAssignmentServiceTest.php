<?php

namespace Tests\Feature\Admission;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\AdmissionIntakeSetting;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\PreEnrollment;
use App\Models\Profile;
use App\Models\Student;
use App\Services\FirstGradeGroupAssignmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class FirstGradeGroupAssignmentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_base_counts_do_not_double_count_candidates(): void
    {
        [$year, $groupA, $groupB] = $this->seedYearWithGroups();

        $this->makeEnrollment($year->id, $groupA->id, [
            'is_new_admission' => false,
            'placement_status' => 'placed',
            'last_name' => 'LOCKED',
        ]);

        $candidate = $this->makeEnrollment($year->id, $groupA->id, [
            'is_new_admission' => true,
            'placement_status' => 'provisional',
            'last_name' => 'CANDIDATE',
            'average' => 9.5,
        ]);

        $result = app(FirstGradeGroupAssignmentService::class)->run(
            academicYearId: $year->id,
            dryRun: true,
        );

        $this->assertSame(1, $result['summary']['total_candidates']);
        $this->assertSame(1, $result['summary']['locked_in_groups']);

        $loads = collect($result['group_loads'])->keyBy('class_group_id');
        // locked(1) + one assigned candidate => A or B totals sum to 2
        $this->assertSame(
            2,
            (int) $loads[$groupA->id]['total'] + (int) $loads[$groupB->id]['total']
        );

        $assignment = collect($result['assignments'])->firstWhere('enrollment_id', $candidate->id);
        $this->assertNotNull($assignment);
        $this->assertTrue($assignment['will_apply']);
    }

    public function test_placed_enrollments_are_not_redistributed(): void
    {
        [$year, $groupA, $groupB] = $this->seedYearWithGroups();

        $placed = $this->makeEnrollment($year->id, $groupA->id, [
            'is_new_admission' => true,
            'placement_status' => 'placed',
            'last_name' => 'PLACED',
            'average' => 10,
        ]);

        $result = app(FirstGradeGroupAssignmentService::class)->run(
            academicYearId: $year->id,
            dryRun: false,
        );

        $this->assertSame(0, $result['summary']['total_candidates']);
        $this->assertSame($groupA->id, $placed->fresh()->class_group_id);
        $this->assertNotSame($groupB->id, $placed->fresh()->class_group_id);
    }

    public function test_require_score_blocks_assignment_without_scores(): void
    {
        [$year, $groupA] = $this->seedYearWithGroups();

        AdmissionIntakeSetting::current()->update([
            'require_score_before_placement' => true,
        ]);

        $candidate = $this->makeEnrollment($year->id, $groupA->id, [
            'is_new_admission' => true,
            'placement_status' => 'provisional',
            'last_name' => 'NOSCORE',
            'skip_pre_enrollment' => true,
        ]);

        $result = app(FirstGradeGroupAssignmentService::class)->run(
            academicYearId: $year->id,
            dryRun: false,
        );

        $assignment = collect($result['assignments'])->firstWhere('enrollment_id', $candidate->id);
        $this->assertContains('missing_score', $assignment['flags']);
        $this->assertFalse($assignment['will_apply']);
        $this->assertSame('provisional', $candidate->fresh()->placement_status);
    }

    public function test_manual_overrides_are_rejected_when_disabled(): void
    {
        [$year, $groupA, $groupB] = $this->seedYearWithGroups();

        AdmissionIntakeSetting::current()->update([
            'allow_manual_group_change' => false,
        ]);

        $candidate = $this->makeEnrollment($year->id, $groupA->id, [
            'is_new_admission' => true,
            'placement_status' => 'provisional',
            'last_name' => 'MANUAL',
            'average' => 8,
        ]);

        $this->expectException(RuntimeException::class);

        app(FirstGradeGroupAssignmentService::class)->run(
            academicYearId: $year->id,
            dryRun: true,
            overrides: [[
                'enrollment_id' => $candidate->id,
                'class_group_id' => $groupB->id,
            ]],
        );
    }

    /**
     * @return array{0: AcademicYear, 1: ClassGroup, 2: ClassGroup}
     */
    private function seedYearWithGroups(): array
    {
        $year = AcademicYear::factory()->create(['is_active' => true]);
        $grade = GradeLevel::create(['name' => '1°']);
        $groupA = ClassGroup::create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'name' => 'A',
        ]);
        $groupB = ClassGroup::create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'name' => 'B',
        ]);

        return [$year, $groupA, $groupB];
    }

    /**
     * @param  array{
     *   is_new_admission?: bool,
     *   placement_status?: string|null,
     *   last_name?: string,
     *   average?: float|null,
     *   exam?: float|null,
     *   skip_pre_enrollment?: bool
     * }  $options
     */
    private function makeEnrollment(int $academicYearId, int $groupId, array $options = []): Enrollment
    {
        $profile = Profile::create([
            'national_id' => strtoupper(fake()->unique()->bothify('????######H?????##')),
            'first_name' => 'Alumno',
            'last_name' => $options['last_name'] ?? 'Prueba',
            'gender' => 'O',
        ]);
        $student = Student::create(['profile_id' => $profile->id]);

        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'class_group_id' => $groupId,
            'academic_year_id' => $academicYearId,
            'status' => EnrollmentStatus::Active,
            'is_new_admission' => $options['is_new_admission'] ?? true,
            'placement_status' => $options['placement_status'] ?? 'provisional',
            'admission_channel' => 'campaign',
        ]);

        if ($options['skip_pre_enrollment'] ?? false) {
            return $enrollment;
        }

        if (array_key_exists('average', $options) || array_key_exists('exam', $options)) {
            PreEnrollment::factory()->create([
                'converted_student_id' => $student->id,
                'current_average' => array_key_exists('average', $options)
                    ? $options['average']
                    : 8.0,
                'admission_exam_score' => array_key_exists('exam', $options)
                    ? $options['exam']
                    : 8.0,
            ]);
        } elseif (($options['is_new_admission'] ?? true) === true) {
            PreEnrollment::factory()->create([
                'converted_student_id' => $student->id,
                'current_average' => 8.5,
                'admission_exam_score' => 8.0,
            ]);
        }

        return $enrollment;
    }
}
