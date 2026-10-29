<?php

namespace Tests\Feature\Students;

use App\Models\Guardian;
use App\Models\Profile;
use App\Models\Student;
use App\Models\User;
use App\Services\People\StudentGuardianPrimaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GuardianPrimaryBackfillTest extends TestCase
{
    use RefreshDatabase;

    public function test_backfill_promotes_first_when_no_primary(): void
    {
        $student = $this->student();
        $a = $this->guardian('ANA');
        $b = $this->guardian('BETO');
        $this->link($student, $a, primary: false, sort: 0);
        $this->link($student, $b, primary: false, sort: 1);

        $stats = app(StudentGuardianPrimaryService::class)->backfillAll();

        $this->assertSame(1, $stats['missing']);
        $this->assertCount(1, $stats['changes']);
        $this->assertSame($student->id, $stats['changes'][0]['student_id']);
        $this->assertSame('missing', $stats['changes'][0]['action']);
        $this->assertSame($a->id, $stats['changes'][0]['primary_guardian_id']);
        $this->assertTrue(
            (bool) DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('guardian_id', $a->id)
                ->value('is_primary')
        );
        $this->assertFalse(
            (bool) DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('guardian_id', $b->id)
                ->value('is_primary')
        );
    }

    public function test_backfill_keeps_one_when_multiple_primaries(): void
    {
        $student = $this->student();
        $a = $this->guardian('ANA');
        $b = $this->guardian('BETO');
        $this->link($student, $a, primary: true, sort: 0);
        $this->link($student, $b, primary: true, sort: 1);

        $stats = app(StudentGuardianPrimaryService::class)->backfillAll();

        $this->assertSame(1, $stats['multiple']);
        $this->assertCount(1, $stats['changes']);
        $this->assertSame('multiple', $stats['changes'][0]['action']);
        $this->assertSame(
            1,
            (int) DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('is_primary', true)
                ->count()
        );
        $this->assertTrue(
            (bool) DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('guardian_id', $a->id)
                ->value('is_primary')
        );
    }

    public function test_dry_run_lists_without_writing(): void
    {
        $student = $this->student();
        $a = $this->guardian('ANA');
        $this->link($student, $a, primary: false, sort: 0);

        $stats = app(StudentGuardianPrimaryService::class)->backfillAll(dryRun: true);

        $this->assertSame(1, $stats['missing']);
        $this->assertSame($student->id, $stats['changes'][0]['student_id']);
        $this->assertFalse(
            (bool) DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('guardian_id', $a->id)
                ->value('is_primary')
        );
    }

    public function test_cannot_clear_only_primary_without_another(): void
    {
        $this->actingEditor();
        $student = $this->student();
        $mother = $this->guardian('ROSA');
        $father = $this->guardian('CARLOS');
        $this->link($student, $mother, primary: true, sort: 0);
        $this->link($student, $father, primary: false, sort: 1);

        $this->patchJson("/api/students/{$student->id}/guardians/{$mother->id}", [
            'first_name' => 'Rosa',
            'last_name' => 'Lopez',
            'relationship' => 'Madre',
            'is_primary' => false,
            'is_secondary' => false,
        ])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['is_primary']);

        $this->assertTrue(
            (bool) DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('guardian_id', $mother->id)
                ->value('is_primary')
        );
    }

    public function test_can_switch_primary_to_another_tutor(): void
    {
        $this->actingEditor();
        $student = $this->student();
        $mother = $this->guardian('ROSA');
        $father = $this->guardian('CARLOS');
        $this->link($student, $mother, primary: true, sort: 0);
        $this->link($student, $father, primary: false, sort: 1);

        $this->patchJson("/api/students/{$student->id}/guardians/{$father->id}", [
            'first_name' => 'Carlos',
            'last_name' => 'Mora',
            'relationship' => 'Padre',
            'is_primary' => true,
            'is_secondary' => false,
        ])->assertOk();

        $this->assertTrue(
            (bool) DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('guardian_id', $father->id)
                ->value('is_primary')
        );
        $this->assertFalse(
            (bool) DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('guardian_id', $mother->id)
                ->value('is_primary')
        );
    }

    public function test_store_heals_when_first_tutor_would_lack_primary(): void
    {
        $this->actingEditor();
        $student = $this->student();

        $this->postJson("/api/students/{$student->id}/guardians", [
            'first_name' => 'Rosa',
            'last_name' => 'Lopez',
            'relationship' => 'Madre',
            'is_primary' => false,
            'is_secondary' => true,
            'address_mode' => 'new',
            'address' => [
                'street_type' => 'Calle',
                'street_name' => 'Reforma',
                'house_number' => '1',
                'neighborhood_type' => 'Colonia',
                'neighborhood_name' => 'Centro',
                'postal_code' => '68000',
                'city' => 'Oaxaca',
                'state' => 'Oaxaca',
            ],
        ])->assertCreated();

        $this->assertSame(
            1,
            (int) DB::table('guardian_student')
                ->where('student_id', $student->id)
                ->where('is_primary', true)
                ->count()
        );
    }

    private function actingEditor(): void
    {
        foreach (['edit students', 'view students'] as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->givePermissionTo(['edit students', 'view students']);
        Sanctum::actingAs($user);
    }

    private function student(): Student
    {
        $profile = Profile::query()->create([
            'first_name' => 'ANA',
            'last_name' => 'TORRES',
            'gender' => 'F',
        ]);

        return Student::query()->create(['profile_id' => $profile->id]);
    }

    private function guardian(string $first): Guardian
    {
        $profile = Profile::query()->create([
            'first_name' => $first,
            'last_name' => 'TEST',
            'gender' => 'F',
        ]);

        return Guardian::query()->create(['profile_id' => $profile->id]);
    }

    private function link(Student $student, Guardian $guardian, bool $primary, int $sort): void
    {
        DB::table('guardian_student')->insert([
            'student_id' => $student->id,
            'guardian_id' => $guardian->id,
            'relationship' => 'Tutor',
            'is_primary' => $primary,
            'is_secondary' => false,
            'sort_order' => $sort,
        ]);
    }
}
