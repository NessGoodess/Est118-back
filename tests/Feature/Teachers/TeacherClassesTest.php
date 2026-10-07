<?php

namespace Tests\Feature\Teachers;

use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\GradeLevel;
use App\Models\Profile;
use App\Models\SchoolClass;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TeacherClassesTest extends TestCase
{
    use RefreshDatabase;

    public function test_sync_assigns_and_clears_classes_of_the_active_year_only(): void
    {
        $this->actingEditor();
        $active = AcademicYear::factory()->create(['is_active' => true, 'year_start' => '2026', 'year_end' => '2027']);
        $other = AcademicYear::factory()->create(['is_active' => false, 'year_start' => '2025', 'year_end' => '2026']);
        $grade = GradeLevel::query()->create(['name' => '1°']);
        $activeGroup = ClassGroup::query()->create([
            'academic_year_id' => $active->id,
            'grade_level_id' => $grade->id,
            'name' => 'A',
        ]);
        $otherGroup = ClassGroup::query()->create([
            'academic_year_id' => $other->id,
            'grade_level_id' => $grade->id,
            'name' => 'A',
        ]);
        $subject = Subject::query()->create(['name' => 'Matemáticas', 'code' => 'MAT']);
        $activeClass = SchoolClass::query()->create([
            'subject_id' => $subject->id,
            'class_group_id' => $activeGroup->id,
        ]);
        $secondActive = SchoolClass::query()->create([
            'subject_id' => $subject->id,
            'class_group_id' => ClassGroup::query()->create([
                'academic_year_id' => $active->id,
                'grade_level_id' => $grade->id,
                'name' => 'B',
            ])->id,
        ]);
        $otherClass = SchoolClass::query()->create([
            'subject_id' => $subject->id,
            'class_group_id' => $otherGroup->id,
            'teacher_id' => null,
        ]);

        $teacher = $this->makeTeacher();
        $otherClass->update(['teacher_id' => $teacher->id]);

        $this->putJson("/api/teachers/{$teacher->id}/classes", [
            'class_ids' => [$activeClass->id, $secondActive->id],
        ])->assertOk();

        $this->assertSame($teacher->id, $activeClass->fresh()->teacher_id);
        $this->assertSame($teacher->id, $secondActive->fresh()->teacher_id);
        $this->assertSame($teacher->id, $otherClass->fresh()->teacher_id);

        $this->putJson("/api/teachers/{$teacher->id}/classes", [
            'class_ids' => [$activeClass->id],
        ])->assertOk();

        $this->assertSame($teacher->id, $activeClass->fresh()->teacher_id);
        $this->assertNull($secondActive->fresh()->teacher_id);
        $this->assertSame($teacher->id, $otherClass->fresh()->teacher_id);

        $this->getJson('/api/teachers/classes/options')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_sync_rejects_classes_outside_the_active_year(): void
    {
        $this->actingEditor();
        AcademicYear::factory()->create(['is_active' => true, 'year_start' => '2026', 'year_end' => '2027']);
        $other = AcademicYear::factory()->create(['is_active' => false, 'year_start' => '2025', 'year_end' => '2026']);
        $grade = GradeLevel::query()->create(['name' => '1°']);
        $subject = Subject::query()->create(['name' => 'Español', 'code' => 'ESP']);
        $class = SchoolClass::query()->create([
            'subject_id' => $subject->id,
            'class_group_id' => ClassGroup::query()->create([
                'academic_year_id' => $other->id,
                'grade_level_id' => $grade->id,
                'name' => 'C',
            ])->id,
        ]);
        $teacher = $this->makeTeacher();

        $this->putJson("/api/teachers/{$teacher->id}/classes", [
            'class_ids' => [$class->id],
        ])->assertStatus(422);
    }

    private function actingEditor(): User
    {
        $user = User::factory()->create();
        foreach (['view teachers', 'edit teachers'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeTeacher(): Teacher
    {
        $profile = Profile::query()->create([
            'first_name' => 'ANA',
            'last_name' => 'DOCENTE',
            'national_id' => 'DOAN800101MOCPZNA8',
            'gender' => 'F',
        ]);

        return Teacher::query()->create([
            'profile_id' => $profile->id,
            'status' => 'active',
        ]);
    }
}
