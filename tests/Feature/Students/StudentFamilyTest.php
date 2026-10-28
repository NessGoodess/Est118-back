<?php

namespace Tests\Feature\Students;

use App\Models\Guardian;
use App\Models\Profile;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StudentFamilyTest extends TestCase
{
    use RefreshDatabase;

    public function test_links_siblings_bidirectionally(): void
    {
        $this->actingEditor();
        $a = $this->student('ANA', 'TORRES');
        $b = $this->student('LUIS', 'TORRES');

        $this->postJson("/api/students/{$a->id}/siblings", [
            'sibling_student_id' => $b->id,
        ])->assertCreated();

        $this->assertTrue($a->fresh()->siblings()->whereKey($b->id)->exists());
        $this->assertTrue($b->fresh()->siblings()->whereKey($a->id)->exists());
    }

    public function test_unlinks_siblings_bidirectionally(): void
    {
        $this->actingEditor();
        $a = $this->student('ANA', 'TORRES');
        $b = $this->student('LUIS', 'TORRES');
        $a->siblings()->syncWithoutDetaching([$b->id]);
        $b->siblings()->syncWithoutDetaching([$a->id]);

        $this->deleteJson("/api/students/{$a->id}/siblings/{$b->id}")
            ->assertOk();

        $this->assertFalse($a->fresh()->siblings()->whereKey($b->id)->exists());
        $this->assertFalse($b->fresh()->siblings()->whereKey($a->id)->exists());
    }

    public function test_sibling_guardians_excludes_already_linked(): void
    {
        $this->actingEditor();
        $a = $this->student('ANA', 'TORRES');
        $b = $this->student('LUIS', 'TORRES');
        $a->siblings()->syncWithoutDetaching([$b->id]);
        $b->siblings()->syncWithoutDetaching([$a->id]);

        $shared = $this->guardian('ROSA', 'LOPEZ', 'LOPR850101MDFPRS09');
        $onlyB = $this->guardian('CARLOS', 'MORA', null);

        $a->guardians()->syncWithoutDetaching([$shared->id => ['relationship' => 'Madre']]);
        $b->guardians()->syncWithoutDetaching([
            $shared->id => ['relationship' => 'Madre'],
            $onlyB->id => ['relationship' => 'Padre'],
        ]);

        $response = $this->getJson("/api/students/{$a->id}/sibling-guardians")->assertOk();
        $ids = collect($response->json('data'))->pluck('id')->all();

        $this->assertContains($onlyB->id, $ids);
        $this->assertNotContains($shared->id, $ids);
    }

    public function test_attach_guardian_from_sibling(): void
    {
        $this->actingEditor();
        $a = $this->student('ANA', 'TORRES');
        $b = $this->student('LUIS', 'TORRES');
        $a->siblings()->syncWithoutDetaching([$b->id]);
        $b->siblings()->syncWithoutDetaching([$a->id]);

        $guardian = $this->guardian('ROSA', 'LOPEZ', null);
        $b->guardians()->syncWithoutDetaching([$guardian->id => ['relationship' => 'Madre']]);

        $this->postJson("/api/students/{$a->id}/guardians/attach", [
            'guardian_id' => $guardian->id,
            'relationship' => 'Madre',
        ])->assertOk();

        $this->assertTrue($a->fresh()->guardians()->whereKey($guardian->id)->exists());
    }

    public function test_attach_rejects_guardian_not_from_sibling(): void
    {
        $this->actingEditor();
        $a = $this->student('ANA', 'TORRES');
        $guardian = $this->guardian('ROSA', 'LOPEZ', null);

        $this->postJson("/api/students/{$a->id}/guardians/attach", [
            'guardian_id' => $guardian->id,
        ])->assertStatus(422);
    }

    public function test_update_guardian_curp_conflict_returns_409(): void
    {
        $this->actingEditor();
        $student = $this->student('ANA', 'TORRES');
        $draft = $this->guardian('ROSA', 'LOPEZ', null);
        $canonical = $this->guardian('ROSA', 'LOPEZ', 'LOPR850101MDFPRS09');
        $student->guardians()->syncWithoutDetaching([$draft->id => ['relationship' => 'Madre']]);

        $this->patchJson("/api/students/{$student->id}/guardians/{$draft->id}", [
            'first_name' => 'Rosa',
            'last_name' => 'Lopez',
            'national_id' => 'LOPR850101MDFPRS09',
            'relationship' => 'Madre',
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'guardian_curp_exists')
            ->assertJsonPath('canonical.guardian_id', $canonical->id);
    }

    public function test_update_guardian_persists_paternal_and_maternal(): void
    {
        $this->actingEditor();
        $student = $this->student('ANA', 'TORRES');
        $guardian = $this->guardian('ROSA', 'LOPEZ', null);
        $student->guardians()->syncWithoutDetaching([$guardian->id => ['relationship' => 'Madre']]);

        $this->patchJson("/api/students/{$student->id}/guardians/{$guardian->id}", [
            'first_name' => 'Rosa',
            'last_name' => 'Lopez',
            'second_last_name' => 'Garcia',
            'relationship' => 'Madre',
        ])
            ->assertOk()
            ->assertJsonPath('data.guardians.0.last_name', 'Lopez')
            ->assertJsonPath('data.guardians.0.second_last_name', 'Garcia');

        $this->assertSame('Lopez', $guardian->fresh()->profile->last_name);
        $this->assertSame('Garcia', $guardian->fresh()->profile->second_last_name);

        // Omitting second_last_name keeps the existing maternal surname.
        $this->patchJson("/api/students/{$student->id}/guardians/{$guardian->id}", [
            'first_name' => 'Rosa Maria',
            'last_name' => 'Lopez',
            'relationship' => 'Madre',
        ])->assertOk();

        $this->assertSame('Garcia', $guardian->fresh()->profile->second_last_name);
    }

    public function test_merge_guardian_by_curp(): void
    {
        $this->actingEditor();
        $student = $this->student('ANA', 'TORRES');
        $draft = $this->guardian('ROSA', 'LOPEZ', null);
        $canonical = $this->guardian('ROSA', 'LOPEZ', 'LOPR850101MDFPRS09');
        $student->guardians()->syncWithoutDetaching([$draft->id => ['relationship' => 'Madre']]);

        $this->postJson("/api/students/{$student->id}/guardians/merge-by-curp", [
            'draft_guardian_id' => $draft->id,
            'canonical_guardian_id' => $canonical->id,
            'survivor' => 'canonical',
            'relationship' => 'Madre',
        ])->assertOk();

        $this->assertTrue($student->fresh()->guardians()->whereKey($canonical->id)->exists());
        $this->assertFalse($student->fresh()->guardians()->whereKey($draft->id)->exists());
        $this->assertDatabaseMissing('guardians', ['id' => $draft->id]);
    }

    public function test_store_guardian_curp_conflict_returns_409(): void
    {
        $this->actingEditor();
        $student = $this->student('ANA', 'TORRES');
        $this->guardian('ROSA', 'LOPEZ', 'LOPR850101MDFPRS09');

        $this->postJson("/api/students/{$student->id}/guardians", [
            'first_name' => 'Rosa',
            'last_name' => 'Lopez',
            'curp' => 'LOPR850101MDFPRS09',
            'relationship' => 'Madre',
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
        ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'guardian_curp_exists');
    }

    public function test_merge_prefers_draft_as_survivor_and_shares_students(): void
    {
        $this->actingEditor();
        $a = $this->student('ANA', 'TORRES');
        $b = $this->student('LUIS', 'TORRES');
        $draft = $this->guardian('ROSA', 'SINCURP', null);
        $canonical = $this->guardian('ROSA', 'LOPEZ', 'LOPR850101MDFPRS09');
        $a->guardians()->syncWithoutDetaching([$draft->id => ['relationship' => 'Madre']]);
        $b->guardians()->syncWithoutDetaching([$canonical->id => ['relationship' => 'Madre']]);

        $this->postJson("/api/students/{$a->id}/guardians/merge-by-curp", [
            'draft_guardian_id' => $draft->id,
            'canonical_guardian_id' => $canonical->id,
            'survivor' => 'draft',
            'field_sources' => [
                'name' => 'canonical',
                'phone' => 'draft',
                'email' => 'draft',
                'address' => 'none',
            ],
            'relationship' => 'Madre',
        ])->assertOk();

        $this->assertTrue($a->fresh()->guardians()->whereKey($draft->id)->exists());
        $this->assertTrue($b->fresh()->guardians()->whereKey($draft->id)->exists());
        $this->assertSame('LOPR850101MDFPRS09', $draft->fresh()->profile->national_id);
        $this->assertSame('LOPEZ', $draft->fresh()->profile->last_name);
        $this->assertDatabaseMissing('guardians', ['id' => $canonical->id]);
    }

    public function test_search_students_by_name(): void
    {
        $this->actingEditor();
        $this->student('ANA', 'TORRES');
        $this->student('LUIS', 'PEREZ');

        $response = $this->getJson('/api/students/search?q=TORR')->assertOk();
        $names = collect($response->json('data'))->pluck('name')->implode(' ');
        $this->assertStringContainsString('TORRES', $names);
        $this->assertStringNotContainsString('PEREZ', $names);
    }

    public function test_search_students_is_case_insensitive_and_token_order_free(): void
    {
        $this->actingEditor();
        $this->student('JUAN', 'PEREZ');
        $this->student('LUIS', 'GARCIA');

        $response = $this->getJson('/api/students/search?q='.urlencode('perez juan'))->assertOk();
        $names = collect($response->json('data'))->pluck('name')->implode(' ');
        $this->assertStringContainsString('JUAN', $names);
        $this->assertStringContainsString('PEREZ', $names);
        $this->assertStringNotContainsString('GARCIA', $names);
    }

    public function test_detach_guardian_unlinks_only_this_student_and_promotes_primary(): void
    {
        $this->actingEditor();
        $student = $this->student('ANA', 'TORRES');
        $sibling = $this->student('LUIS', 'TORRES');
        $mother = $this->guardian('ROSA', 'LOPEZ', null);
        $father = $this->guardian('CARLOS', 'MORA', null);

        $student->guardians()->syncWithoutDetaching([
            $mother->id => ['relationship' => 'Madre', 'is_primary' => true, 'sort_order' => 0],
            $father->id => ['relationship' => 'Padre', 'is_primary' => false, 'sort_order' => 1],
        ]);
        $sibling->guardians()->syncWithoutDetaching([
            $mother->id => ['relationship' => 'Madre', 'is_primary' => true, 'sort_order' => 0],
        ]);

        $this->deleteJson("/api/students/{$student->id}/guardians/{$mother->id}")
            ->assertOk()
            ->assertJsonPath('data.guardians.0.id', $father->id)
            ->assertJsonPath('data.guardians.0.is_primary', true);

        $this->assertFalse($student->fresh()->guardians()->whereKey($mother->id)->exists());
        $this->assertTrue($student->fresh()->guardians()->whereKey($father->id)->exists());
        $this->assertTrue($sibling->fresh()->guardians()->whereKey($mother->id)->exists());
        $this->assertDatabaseHas('guardians', ['id' => $mother->id]);
    }

    public function test_detach_guardian_not_linked_is_rejected(): void
    {
        $this->actingEditor();
        $student = $this->student('ANA', 'TORRES');
        $guardian = $this->guardian('ROSA', 'LOPEZ', null);

        $this->deleteJson("/api/students/{$student->id}/guardians/{$guardian->id}")
            ->assertUnprocessable();
    }

    private function student(string $first, string $last): Student
    {
        $profile = Profile::query()->create([
            'national_id' => null,
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'F',
        ]);

        return Student::query()->create(['profile_id' => $profile->id]);
    }

    private function guardian(string $first, string $last, ?string $curp): Guardian
    {
        $profile = Profile::query()->create([
            'national_id' => $curp,
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'F',
        ]);

        return Guardian::query()->create(['profile_id' => $profile->id]);
    }

    private function actingEditor(): void
    {
        $user = User::factory()->create();
        foreach (['view students', 'edit students'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);
    }
}
