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

class StoreStudentGuardianTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_guardian_with_own_address(): void
    {
        $this->actingEditor();
        $student = $this->student();

        $response = $this->postJson("/api/students/{$student->id}/guardians", [
            'first_name' => 'Rosa',
            'last_name' => 'López',
            'second_last_name' => 'García',
            'curp' => 'LOGR850101MDFPRS09',
            'birth_date' => '1985-01-01',
            'gender' => 'F',
            'phone' => '7221234567',
            'email' => 'rosa@example.com',
            'relationship' => 'Madre',
            'address_mode' => 'new',
            'address' => [
                'street_type' => 'Calle',
                'street_name' => 'Reforma',
                'house_number' => '20',
                'unit_number' => null,
                'neighborhood_type' => 'Colonia',
                'neighborhood_name' => 'Centro',
                'postal_code' => '50000',
                'city' => 'Toluca',
                'state' => 'México',
            ],
        ])->assertCreated();

        $response->assertJsonPath('data.guardians.0.first_name', 'Rosa')
            ->assertJsonPath('data.guardians.0.relationship', 'Madre');

        $guardian = Guardian::query()->whereHas(
            'profile',
            fn ($q) => $q->where('national_id', 'LOGR850101MDFPRS09')
        )->first();

        $this->assertNotNull($guardian);
        $this->assertTrue($student->guardians()->whereKey($guardian->id)->exists());
        $this->assertSame('Reforma', $guardian->profile->address?->street_name);
        $this->assertNotSame($student->profile->address_id, $guardian->profile->address_id);
    }

    public function test_links_guardian_to_student_address(): void
    {
        $this->actingEditor();
        $address = Address::query()->create([
            'street_type' => 'Calle',
            'street_name' => 'Independencia',
            'house_number' => '10',
            'neighborhood_type' => 'Colonia',
            'neighborhood_name' => 'Centro',
            'postal_code' => '68000',
            'city' => 'Oaxaca',
            'state' => 'Oaxaca',
        ]);
        $student = $this->student($address->id);

        $this->postJson("/api/students/{$student->id}/guardians", [
            'first_name' => 'Carlos',
            'last_name' => 'Mora',
            'curp' => null,
            'birth_date' => '1980-05-15',
            'gender' => 'M',
            'phone' => '9511234567',
            'email' => 'carlos@example.com',
            'relationship' => 'Padre',
            'address_mode' => 'student',
        ])->assertCreated()
            ->assertJsonPath('data.guardians.0.relationship', 'Padre');

        $guardian = $student->fresh()->guardians()->first();
        $this->assertNotNull($guardian);
        $this->assertSame($address->id, $guardian->profile->address_id);
    }

    public function test_student_address_mode_fails_without_address(): void
    {
        $this->actingEditor();
        $student = $this->student();

        $this->postJson("/api/students/{$student->id}/guardians", [
            'first_name' => 'Carlos',
            'last_name' => 'Mora',
            'birth_date' => '1980-05-15',
            'gender' => 'M',
            'phone' => '9511234567',
            'email' => 'carlos@example.com',
            'relationship' => 'Padre',
            'address_mode' => 'student',
        ])->assertStatus(422)
            ->assertJsonPath('message', 'El alumno no tiene domicilio capturado.');
    }

    private function student(?int $addressId = null): Student
    {
        $profile = Profile::query()->create([
            'national_id' => null,
            'first_name' => 'ANA',
            'last_name' => 'TORRES',
            'gender' => 'F',
            'address_id' => $addressId,
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
