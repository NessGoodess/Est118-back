<?php

namespace Tests\Feature\Students;

use App\Models\Profile;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StudentProfileUpdateTest extends TestCase
{
    use RefreshDatabase;

    public function test_profile_update_stores_surnames_separately_and_keeps_second_phone(): void
    {
        $this->actingEditor();
        $student = $this->student();

        $this->patchJson("/api/students/{$student->id}", [
            'profile' => [
                'first_name' => 'ANA',
                'last_name' => 'DE LA TORRE',
                'second_last_name' => 'GONZALEZ',
                'curp' => 'togA100101mocrnna1',
                'birth_date' => '2010-01-01',
                'gender' => 'F',
                'phone' => '9511234567',
                'email' => 'ana@example.com',
                'phone_second_number' => '9517654321',
            ],
        ])->assertOk()
            ->assertJsonPath('data.student_info.second_last_name', 'GONZALEZ')
            ->assertJsonPath('data.student_info.full_name', 'ANA DE LA TORRE GONZALEZ');

        $profile = $student->profile->fresh();
        $this->assertSame('DE LA TORRE', $profile->last_name);
        $this->assertSame('GONZALEZ', $profile->second_last_name);
        $this->assertSame('TOGA100101MOCRNNA1', $profile->national_id);
        $this->assertSame('9511234567', $profile->phone_number);
        $this->assertSame('9517654321', $profile->phone_second_number);

        $this->patchJson("/api/students/{$student->id}", [
            'profile' => ['first_name' => 'ANA MARIA'],
        ])->assertOk();

        $profile->refresh();
        $this->assertSame('ANA MARIA', $profile->first_name);
        $this->assertSame('DE LA TORRE', $profile->last_name);
        $this->assertSame('GONZALEZ', $profile->second_last_name);
        $this->assertSame('9517654321', $profile->phone_second_number);
    }

    public function test_legacy_profile_keys_are_still_accepted(): void
    {
        $this->actingEditor();
        $student = $this->student();

        $this->patchJson("/api/students/{$student->id}", [
            'profile' => [
                'national_id' => 'TOGA100101MOCRNNA1',
                'phone_number' => '9510000000',
            ],
        ])->assertOk();

        $profile = $student->profile->fresh();
        $this->assertSame('TOGA100101MOCRNNA1', $profile->national_id);
        $this->assertSame('9510000000', $profile->phone_number);
    }

    public function test_duplicate_curp_is_rejected(): void
    {
        $this->actingEditor();
        Profile::query()->create([
            'national_id' => 'TOGA100101MOCRNNA1',
            'first_name' => 'OTRA',
            'last_name' => 'PERSONA',
        ]);
        $student = $this->student();

        $this->patchJson("/api/students/{$student->id}", [
            'profile' => ['curp' => 'TOGA100101MOCRNNA1'],
        ])->assertStatus(422)
            ->assertJsonPath('message', 'Ya existe una persona con esa CURP.');
    }

    public function test_invalid_curp_fails_validation(): void
    {
        $this->actingEditor();
        $student = $this->student();

        $this->patchJson("/api/students/{$student->id}", [
            'profile' => ['curp' => 'NO-ES-CURP'],
        ])->assertUnprocessable()->assertJsonValidationErrors('profile.curp');
    }

    public function test_address_update_maps_legacy_apartment_key_and_allows_partial_patch(): void
    {
        $this->actingEditor();
        $student = $this->student();

        $this->patchJson("/api/students/{$student->id}", [
            'address' => [
                'street_type' => 'Calle',
                'street_name' => 'Independencia',
                'house_number' => '10',
                'apartament_number' => '3B',
                'neighborhood_type' => 'Colonia',
                'neighborhood_name' => 'Centro',
                'postal_code' => '68000',
                'city' => 'Oaxaca de Juárez',
                'state' => 'Oaxaca',
            ],
        ])->assertOk()
            ->assertJsonPath('data.address_detail.unit_number', '3B');

        $this->patchJson("/api/students/{$student->id}", [
            'address' => ['house_number' => '12'],
        ])->assertOk();

        $address = $student->profile->fresh()->address;
        $this->assertSame('12', $address->house_number);
        $this->assertSame('3B', $address->unit_number);
        $this->assertSame('Independencia', $address->street_name);
    }

    public function test_update_requires_permission(): void
    {
        Sanctum::actingAs(User::factory()->create());
        $student = $this->student();

        $this->patchJson("/api/students/{$student->id}", [
            'profile' => ['first_name' => 'ANA'],
        ])->assertForbidden();
    }

    private function student(): Student
    {
        $profile = Profile::query()->create([
            'national_id' => null,
            'first_name' => 'ANA',
            'last_name' => 'TORRES GOMEZ',
            'gender' => 'F',
        ]);

        return Student::query()->create(['profile_id' => $profile->id]);
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
