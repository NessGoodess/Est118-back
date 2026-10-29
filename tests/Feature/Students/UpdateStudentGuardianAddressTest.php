<?php

namespace Tests\Feature\Students;

use App\Models\Address;
use App\Models\Guardian;
use App\Models\Profile;
use App\Models\Student;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class UpdateStudentGuardianAddressTest extends TestCase
{
    use RefreshDatabase;

    public function test_update_links_guardian_to_student_address(): void
    {
        $this->actingEditor();
        $studentAddress = $this->address('Independencia', '10');
        $student = $this->student('ANA', 'TORRES', $studentAddress->id);
        $guardian = $this->guardian('ROSA', 'LOPEZ');
        $student->guardians()->syncWithoutDetaching([
            $guardian->id => ['relationship' => 'Madre', 'is_primary' => true],
        ]);

        $this->patchJson("/api/students/{$student->id}/guardians/{$guardian->id}", [
            'first_name' => 'Rosa',
            'last_name' => 'Lopez',
            'relationship' => 'Madre',
            'address_mode' => 'student',
        ])
            ->assertOk()
            ->assertJsonPath('data.guardians.0.shares_student_address', true)
            ->assertJsonPath('data.guardians.0.address_id', $studentAddress->id);

        $this->assertSame($studentAddress->id, $guardian->fresh()->profile->address_id);
    }

    public function test_update_new_address_does_not_mutate_shared_student_address(): void
    {
        $this->actingEditor();
        $studentAddress = $this->address('Independencia', '10');
        $student = $this->student('ANA', 'TORRES', $studentAddress->id);
        $guardian = $this->guardian('ROSA', 'LOPEZ', $studentAddress->id);
        $student->guardians()->syncWithoutDetaching([
            $guardian->id => ['relationship' => 'Madre', 'is_primary' => true],
        ]);

        $this->patchJson("/api/students/{$student->id}/guardians/{$guardian->id}", [
            'first_name' => 'Rosa',
            'last_name' => 'Lopez',
            'relationship' => 'Madre',
            'address_mode' => 'new',
            'address' => [
                'street_type' => 'Calle',
                'street_name' => 'Reforma',
                'house_number' => '99',
                'unit_number' => null,
                'neighborhood_type' => 'Colonia',
                'neighborhood_name' => 'Centro',
                'postal_code' => '50000',
                'city' => 'Toluca',
                'state' => 'México',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.guardians.0.shares_student_address', false)
            ->assertJsonPath('data.guardians.0.address_detail.street_name', 'Reforma');

        $guardian->refresh()->load('profile.address');
        $student->refresh()->load('profile.address');

        $this->assertNotSame($studentAddress->id, $guardian->profile->address_id);
        $this->assertSame('Reforma', $guardian->profile->address?->street_name);
        $this->assertSame('Independencia', $student->profile->address?->street_name);
        $this->assertSame('10', $student->profile->address?->house_number);
        $this->assertSame($studentAddress->id, $student->profile->address_id);
    }

    public function test_update_student_address_mode_fails_without_student_address(): void
    {
        $this->actingEditor();
        $student = $this->student('ANA', 'TORRES');
        $guardian = $this->guardian('ROSA', 'LOPEZ');
        $student->guardians()->syncWithoutDetaching([
            $guardian->id => ['relationship' => 'Madre', 'is_primary' => true],
        ]);

        $this->patchJson("/api/students/{$student->id}/guardians/{$guardian->id}", [
            'first_name' => 'Rosa',
            'last_name' => 'Lopez',
            'relationship' => 'Madre',
            'address_mode' => 'student',
        ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['address_mode']);
    }

    public function test_detail_exposes_shares_student_address_flag(): void
    {
        $this->actingEditor();
        $shared = $this->address('Independencia', '10');
        $own = $this->address('Reforma', '20');
        $student = $this->student('ANA', 'TORRES', $shared->id);
        $linked = $this->guardian('ROSA', 'LOPEZ', $shared->id);
        $separate = $this->guardian('CARLOS', 'MORA', $own->id);
        $student->guardians()->syncWithoutDetaching([
            $linked->id => ['relationship' => 'Madre', 'is_primary' => true, 'sort_order' => 0],
            $separate->id => ['relationship' => 'Padre', 'is_primary' => false, 'sort_order' => 1],
        ]);

        $response = $this->getJson("/api/students/{$student->id}")->assertOk();
        $byId = collect($response->json('data.guardians'))->keyBy('id');

        $this->assertTrue($byId[$linked->id]['shares_student_address']);
        $this->assertFalse($byId[$separate->id]['shares_student_address']);
        $this->assertSame('Independencia', $byId[$linked->id]['address_detail']['street_name']);
        $this->assertSame('Reforma', $byId[$separate->id]['address_detail']['street_name']);
    }

    private function address(string $street, string $number): Address
    {
        return Address::query()->create([
            'street_type' => 'Calle',
            'street_name' => $street,
            'house_number' => $number,
            'neighborhood_type' => 'Colonia',
            'neighborhood_name' => 'Centro',
            'postal_code' => '68000',
            'city' => 'Oaxaca',
            'state' => 'Oaxaca',
        ]);
    }

    private function student(string $first, string $last, ?int $addressId = null): Student
    {
        $profile = Profile::query()->create([
            'national_id' => null,
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'F',
            'address_id' => $addressId,
        ]);

        return Student::query()->create(['profile_id' => $profile->id]);
    }

    private function guardian(string $first, string $last, ?int $addressId = null): Guardian
    {
        $profile = Profile::query()->create([
            'national_id' => null,
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'F',
            'address_id' => $addressId,
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
