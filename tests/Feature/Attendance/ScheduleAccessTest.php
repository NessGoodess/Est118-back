<?php

namespace Tests\Feature\Attendance;

use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Profile;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Enums\EnrollmentStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ScheduleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_resource_exposes_teacher_id_and_schedule_scope(): void
    {
        $user = User::factory()->create();
        $profile = $this->makeProfile($user, 'Ana', 'Docente');
        $teacher = Teacher::create(['profile_id' => $profile->id, 'status' => 'active']);
        $this->grant($user, ['view attendance', 'view own schedules']);
        Sanctum::actingAs($user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.teacher_id', $teacher->id)
            ->assertJsonPath('data.schedule_scope', 'own');
    }

    public function test_teacher_sees_only_own_schedules(): void
    {
        [$own, $other] = $this->twoTeacherSchedules();
        $user = $this->userLinkedToTeacher($own['teacher']);
        $this->grant($user, ['view attendance', 'view own schedules']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/schedules');
        $response->assertOk();
        $ids = collect($response->json('schedules'))->pluck('id');
        $this->assertTrue($ids->contains($own['schedule']->id));
        $this->assertFalse($ids->contains($other['schedule']->id));
        $this->assertSame('own', $response->json('scope'));
    }

    public function test_teacher_without_profile_gets_empty_schedule_list(): void
    {
        $this->twoTeacherSchedules();
        $user = User::factory()->create();
        $this->grant($user, ['view attendance', 'view own schedules']);
        Sanctum::actingAs($user);

        $this->getJson('/api/schedules')
            ->assertOk()
            ->assertJsonPath('scope', 'own')
            ->assertJsonCount(0, 'schedules');
    }

    public function test_teacher_cannot_record_attendance_on_foreign_slot(): void
    {
        [$own, $other] = $this->twoTeacherSchedules();
        $user = $this->userLinkedToTeacher($own['teacher']);
        $this->grant($user, ['view attendance', 'edit attendance', 'view own schedules']);
        Sanctum::actingAs($user);

        $this->postJson('/api/record', [
            'student_id' => $other['student']->id,
            'schedule_id' => $other['schedule']->id,
            'date' => '2026-09-14',
            'status' => 'present',
        ])->assertForbidden();

        $this->assertSame(0, Attendance::query()->count());
    }

    public function test_staff_filters_by_group_and_can_filter_by_teacher(): void
    {
        [$own, $other] = $this->twoTeacherSchedules();
        $user = User::factory()->create();
        $this->grant($user, ['view attendance', 'view group schedules']);
        Sanctum::actingAs($user);

        $this->getJson('/api/schedules')
            ->assertOk()
            ->assertJsonPath('scope', 'group')
            ->assertJsonCount(0, 'schedules');

        $byGroup = $this->getJson('/api/schedules?class_group_id='.$own['group']->id);
        $byGroup->assertOk();
        $groupIds = collect($byGroup->json('schedules'))->pluck('id');
        $this->assertTrue($groupIds->contains($own['schedule']->id));
        $this->assertFalse($groupIds->contains($other['schedule']->id));

        $byTeacher = $this->getJson('/api/schedules?teacher_id='.$other['teacher']->id);
        $byTeacher->assertOk();
        $teacherIds = collect($byTeacher->json('schedules'))->pluck('id');
        $this->assertTrue($teacherIds->contains($other['schedule']->id));
        $this->assertFalse($teacherIds->contains($own['schedule']->id));
    }

    public function test_admin_sees_all_schedules_without_filter(): void
    {
        [$own, $other] = $this->twoTeacherSchedules();
        $user = User::factory()->create();
        $this->grant($user, ['view attendance', 'view all schedules']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/schedules');
        $response->assertOk()->assertJsonPath('scope', 'all');
        $ids = collect($response->json('schedules'))->pluck('id');
        $this->assertTrue($ids->contains($own['schedule']->id));
        $this->assertTrue($ids->contains($other['schedule']->id));
    }

    /**
     * @param  list<string>  $names
     */
    private function grant(User $user, array $names): void
    {
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $user->givePermissionTo($names);
    }

    private function makeProfile(?User $user, string $first, string $last): Profile
    {
        return Profile::create([
            'user_id' => $user?->id,
            'national_id' => strtoupper(fake()->unique()->bothify('????######H?????##')),
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'F',
        ]);
    }

    private function userLinkedToTeacher(Teacher $teacher): User
    {
        $user = User::factory()->create();
        $teacher->profile->update(['user_id' => $user->id]);

        return $user->fresh();
    }

    /**
     * @return array{0: array{teacher: Teacher, schedule: Schedule, group: ClassGroup, student: Student}, 1: array{teacher: Teacher, schedule: Schedule, group: ClassGroup, student: Student}}
     */
    private function twoTeacherSchedules(): array
    {
        $year = AcademicYear::factory()->create(['is_active' => true]);
        $grade = GradeLevel::create(['name' => '1°']);
        $subject = Subject::query()->firstOrCreate(['name' => 'Español']);

        $make = function (string $letter, string $first) use ($year, $grade, $subject): array {
            $group = ClassGroup::create([
                'academic_year_id' => $year->id,
                'grade_level_id' => $grade->id,
                'name' => $letter,
            ]);
            $profile = $this->makeProfile(null, $first, 'Maestro');
            $teacher = Teacher::create(['profile_id' => $profile->id, 'status' => 'active']);
            $schoolClass = SchoolClass::create([
                'subject_id' => $subject->id,
                'class_group_id' => $group->id,
                'teacher_id' => $teacher->id,
            ]);
            $schedule = Schedule::create([
                'school_class_id' => $schoolClass->id,
                'teacher_id' => $teacher->id,
                'day' => 'Lunes',
                'start_time' => '07:00:00',
                'end_time' => '07:45:00',
                'schedule_type' => 'regular',
            ]);
            $studentProfile = $this->makeProfile(null, 'Alumno', $letter);
            $student = Student::create(['profile_id' => $studentProfile->id]);
            Enrollment::create([
                'student_id' => $student->id,
                'class_group_id' => $group->id,
                'academic_year_id' => $year->id,
                'status' => EnrollmentStatus::Active,
            ]);

            return compact('teacher', 'schedule', 'group', 'student');
        };

        return [$make('A', 'Uno'), $make('B', 'Dos')];
    }
}
