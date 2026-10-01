<?php

namespace Tests\Feature\Students;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Profile;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StudentDirectoryByYearTest extends TestCase
{
    use RefreshDatabase;

    public function test_academic_years_lists_the_active_cycle_first(): void
    {
        Sanctum::actingAs($this->viewer());

        $older = AcademicYear::factory()->create([
            'year_start' => '2024',
            'year_end' => '2025',
            'starts_on' => '2024-08-01',
            'ends_on' => '2025-07-31',
            'is_active' => false,
        ]);
        $active = AcademicYear::factory()->create([
            'year_start' => '2026',
            'year_end' => '2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_active' => true,
        ]);
        $recent = AcademicYear::factory()->create([
            'year_start' => '2025',
            'year_end' => '2026',
            'starts_on' => '2025-08-01',
            'ends_on' => '2026-07-31',
            'is_active' => false,
        ]);

        $response = $this->getJson('/api/students/academic-years')->assertOk();

        $ids = collect($response->json('data'))->pluck('id')->all();
        $this->assertSame([$active->id, $recent->id, $older->id], $ids);
        $this->assertTrue($response->json('data.0.is_active'));
    }

    public function test_directory_splits_a_student_across_cycles_and_keeps_dropouts_in_history(): void
    {
        Sanctum::actingAs($this->viewer());

        [$past, $current, $first, $second] = $this->twoCycles();
        $ana = $this->student('Ana', 'López');
        $luis = $this->student('Luis', 'Mora');

        $this->enroll($ana, $past['group'], EnrollmentStatus::Completed);
        $this->enroll($ana, $current['group'], EnrollmentStatus::Active);
        $this->enroll($luis, $past['group'], EnrollmentStatus::Dropped);

        $history = $this->getJson("/api/students/grades/{$first->id}?academic_year_id={$past['year']->id}")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $history);
        $anaHistory = collect($history)->firstWhere('id', $ana->id);
        $this->assertSame('1°', $anaHistory['grade_level']);
        $this->assertSame('A', $anaHistory['class_group']);
        $this->assertSame('completed', $anaHistory['enrollment_status']);
        $this->assertSame('2025-2026', $anaHistory['academic_year']);
        $this->assertSame('dropped', collect($history)->firstWhere('id', $luis->id)['enrollment_status']);

        $currentRows = $this->getJson("/api/students/grades/{$second->id}?academic_year_id={$current['year']->id}")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $currentRows);
        $this->assertSame($ana->id, $currentRows[0]['id']);
        $this->assertSame('2°', $currentRows[0]['grade_level']);
        $this->assertSame('active', $currentRows[0]['enrollment_status']);
        $this->assertSame('2026-2027', $currentRows[0]['academic_year']);

        $this->getJson("/api/students/grades/{$first->id}?academic_year_id={$current['year']->id}")
            ->assertOk()
            ->assertJsonPath('data', []);

        $droppedOnly = $this->getJson("/api/students/grades/{$first->id}?academic_year_id={$past['year']->id}&status[]=dropped")
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $droppedOnly);
        $this->assertSame($luis->id, $droppedOnly[0]['id']);
    }

    public function test_grade_counts_and_default_list_use_the_active_cycle(): void
    {
        Sanctum::actingAs($this->viewer());

        [$past, $current, $first, $second] = $this->twoCycles();
        $ana = $this->student('Ana', 'López');
        $luis = $this->student('Luis', 'Mora');
        $this->enroll($ana, $past['group'], EnrollmentStatus::Completed);
        $this->enroll($ana, $current['group'], EnrollmentStatus::Active);
        $this->enroll($luis, $past['group'], EnrollmentStatus::Dropped);

        $activeGrades = $this->getJson('/api/students/grades')->assertOk();
        $this->assertSame($current['year']->id, $activeGrades->json('data.academic_year_id'));
        $this->assertSame(0, (int) collect($activeGrades->json('data.grades'))->firstWhere('grade_id', $first->id)['total_students']);
        $this->assertSame(1, (int) collect($activeGrades->json('data.grades'))->firstWhere('grade_id', $second->id)['total_students']);

        $pastGrades = $this->getJson("/api/students/grades?academic_year_id={$past['year']->id}")->assertOk();
        $this->assertSame(2, (int) collect($pastGrades->json('data.grades'))->firstWhere('grade_id', $first->id)['total_students']);
        $this->assertSame(0, (int) collect($pastGrades->json('data.grades'))->firstWhere('grade_id', $second->id)['total_students']);

        $defaultList = $this->getJson("/api/students/grades/{$second->id}")->assertOk()->json('data');
        $this->assertCount(1, $defaultList);
        $this->assertSame($ana->id, $defaultList[0]['id']);
        $this->assertSame('2026-2027', $defaultList[0]['academic_year']);
    }

    public function test_rejects_unknown_academic_year_and_requires_permission(): void
    {
        Sanctum::actingAs($this->viewer());

        $this->getJson('/api/students/grades?academic_year_id=9999')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('academic_year_id');

        Sanctum::actingAs(User::factory()->create());

        $this->getJson('/api/students/academic-years')->assertForbidden();
        $this->getJson('/api/students/grades')->assertForbidden();
    }

    /**
     * @return array{0: array{year: AcademicYear, group: ClassGroup}, 1: array{year: AcademicYear, group: ClassGroup}, 2: GradeLevel, 3: GradeLevel}
     */
    private function twoCycles(): array
    {
        $pastYear = AcademicYear::factory()->create([
            'year_start' => '2025',
            'year_end' => '2026',
            'starts_on' => '2025-08-01',
            'ends_on' => '2026-07-31',
            'is_active' => false,
        ]);
        $currentYear = AcademicYear::factory()->create([
            'year_start' => '2026',
            'year_end' => '2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_active' => true,
        ]);
        $first = GradeLevel::query()->create(['name' => '1°']);
        $second = GradeLevel::query()->create(['name' => '2°']);
        $pastGroup = ClassGroup::query()->create([
            'academic_year_id' => $pastYear->id,
            'grade_level_id' => $first->id,
            'name' => 'A',
        ]);
        $currentGroup = ClassGroup::query()->create([
            'academic_year_id' => $currentYear->id,
            'grade_level_id' => $second->id,
            'name' => 'B',
        ]);

        return [
            ['year' => $pastYear, 'group' => $pastGroup],
            ['year' => $currentYear, 'group' => $currentGroup],
            $first,
            $second,
        ];
    }

    private function enroll(Student $student, ClassGroup $group, EnrollmentStatus $status): Enrollment
    {
        return Enrollment::query()->create([
            'student_id' => $student->id,
            'class_group_id' => $group->id,
            'academic_year_id' => $group->academic_year_id,
            'status' => $status,
        ]);
    }

    private function student(string $firstName, string $lastName): Student
    {
        $profile = Profile::query()->create([
            'national_id' => strtoupper(fake()->unique()->bothify('????######H?????##')),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => 'O',
        ]);

        return Student::query()->create(['profile_id' => $profile->id]);
    }

    private function viewer(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('view students', 'web');
        $user->givePermissionTo('view students');

        return $user;
    }
}
