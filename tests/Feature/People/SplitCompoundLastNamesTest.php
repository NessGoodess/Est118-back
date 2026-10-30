<?php

namespace Tests\Feature\People;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Profile;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SplitCompoundLastNamesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_two_word_splits_and_does_not_write(): void
    {
        [$year, $group] = $this->period();
        $split = $this->enrolledStudent($year, $group, 'ANA', 'GARCIA LOPEZ', null);
        $both = $this->enrolledStudent($year, $group, 'LUIS', 'PEREZ', 'RAMIREZ');
        $many = $this->enrolledStudent($year, $group, 'MARIA', 'DE LA CRUZ', '');

        $this->artisan('profiles:split-compound-last-names', ['--dry-run' => true])
            ->assertSuccessful();

        $this->assertSame('GARCIA LOPEZ', $split->fresh()->last_name);
        $this->assertNull($split->fresh()->second_last_name);
        $this->assertSame('PEREZ', $both->fresh()->last_name);
        $this->assertSame('RAMIREZ', $both->fresh()->second_last_name);
        $this->assertSame('DE LA CRUZ', $many->fresh()->last_name);
    }

    public function test_apply_moves_second_word_to_maternal(): void
    {
        [$year, $group] = $this->period();
        $split = $this->enrolledStudent($year, $group, 'ANA', 'GARCIA LOPEZ', null);
        $many = $this->enrolledStudent($year, $group, 'MARIA', 'DE LA CRUZ', null);

        $this->artisan('profiles:split-compound-last-names', ['--yes' => true])
            ->assertSuccessful();

        $split->refresh();
        $this->assertSame('GARCIA', $split->last_name);
        $this->assertSame('LOPEZ', $split->second_last_name);

        $many->refresh();
        $this->assertSame('DE LA CRUZ', $many->last_name);
        $this->assertTrue($many->second_last_name === null || $many->second_last_name === '');
    }

    public function test_ignores_students_not_active_in_2026_2027(): void
    {
        [$year, $group] = $this->period();
        $active = $this->enrolledStudent($year, $group, 'ANA', 'GARCIA LOPEZ', null);

        $otherYear = AcademicYear::factory()->create([
            'year_start' => '2025',
            'year_end' => '2026',
            'is_active' => false,
        ]);
        $otherGroup = ClassGroup::query()->create([
            'academic_year_id' => $otherYear->id,
            'grade_level_id' => $group->grade_level_id,
            'name' => 'A',
        ]);
        $outside = $this->enrolledStudent($otherYear, $otherGroup, 'PEDRO', 'MARTINEZ SANCHEZ', null);

        $this->artisan('profiles:split-compound-last-names', ['--yes' => true])
            ->assertSuccessful();

        $active->refresh();
        $this->assertSame('GARCIA', $active->last_name);
        $this->assertSame('LOPEZ', $active->second_last_name);

        $outside->refresh();
        $this->assertSame('MARTINEZ SANCHEZ', $outside->last_name);
        $this->assertNull($outside->second_last_name);
    }

    /**
     * @return array{0: AcademicYear, 1: ClassGroup}
     */
    private function period(): array
    {
        $year = AcademicYear::factory()->create([
            'is_active' => true,
            'year_start' => '2026',
            'year_end' => '2027',
        ]);
        $grade = GradeLevel::query()->firstOrCreate(['name' => '2°'], ['sort_order' => 2]);
        $group = ClassGroup::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'name' => 'A',
        ]);

        return [$year, $group];
    }

    private function enrolledStudent(
        AcademicYear $year,
        ClassGroup $group,
        string $first,
        string $last,
        ?string $second
    ): Profile {
        $profile = Profile::query()->create([
            'first_name' => $first,
            'last_name' => $last,
            'second_last_name' => $second,
            'gender' => 'F',
        ]);
        $student = Student::query()->create(['profile_id' => $profile->id]);
        Enrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'class_group_id' => $group->id,
            'status' => EnrollmentStatus::Active,
        ]);

        return $profile;
    }
}
