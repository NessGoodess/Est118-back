<?php

namespace Tests\Feature\Teachers;

use App\Models\Profile;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TeacherCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_show_update_and_deactivate_teacher(): void
    {
        $user = $this->actingTeacherAdmin();

        $response = $this->postJson('/api/teachers', $this->payload());
        $response->assertCreated();
        $id = $response->json('data.id');
        $this->assertNotNull($id);
        $profile = Teacher::query()->find($id)?->profile;
        $this->assertSame('VILLANUEVA', $profile?->last_name);
        $this->assertSame('MARTINEZ', $profile?->second_last_name);
        $this->assertSame('ARACELI VILLANUEVA MARTINEZ', $profile?->fullName());
        $this->assertSame('16', Teacher::query()->find($id)?->classroom);

        $this->getJson("/api/teachers/{$id}")
            ->assertOk()
            ->assertJsonPath('data.personal.first_name', 'ARACELI')
            ->assertJsonPath('data.personal.last_name', 'VILLANUEVA')
            ->assertJsonPath('data.personal.second_last_name', 'MARTINEZ')
            ->assertJsonPath('data.address.city', 'Oaxaca de Juárez')
            ->assertJsonPath('data.job.employee', 'A-001');

        $this->patchJson("/api/teachers/{$id}", [
            'personal' => [
                ...$this->personFields(),
                'first_name' => 'ARACELY',
            ],
        ])->assertOk()->assertJsonPath('data.personal.first_name', 'ARACELY');

        $this->patchJson("/api/teachers/{$id}/status", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.job.status', 'inactive');

        $this->getJson('/api/teachers?status=inactive')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_compound_surnames_round_trip(): void
    {
        $this->actingTeacherAdmin();
        $id = $this->postJson('/api/teachers', [
            ...$this->payload(),
            'last_name' => 'DE LA TORRE',
            'second_last_name' => 'GONZALEZ',
        ])->assertCreated()->json('data.id');

        $this->getJson("/api/teachers/{$id}")
            ->assertOk()
            ->assertJsonPath('data.personal.last_name', 'DE LA TORRE')
            ->assertJsonPath('data.personal.second_last_name', 'GONZALEZ');

        $this->getJson('/api/teachers')
            ->assertOk()
            ->assertJsonPath('data.0.last_name', 'DE LA TORRE GONZALEZ');

        $this->patchJson("/api/teachers/{$id}", [
            'personal' => [
                ...$this->personFields(),
                'last_name' => 'PEREZ',
                'second_last_name' => 'DE LOS ANGELES',
            ],
        ])->assertOk()
            ->assertJsonPath('data.personal.last_name', 'PEREZ')
            ->assertJsonPath('data.personal.second_last_name', 'DE LOS ANGELES');
    }

    public function test_duplicate_curp_is_rejected(): void
    {
        $this->actingTeacherAdmin();
        $this->postJson('/api/teachers', $this->payload())->assertCreated();

        $this->postJson('/api/teachers', $this->payload())
            ->assertStatus(422)
            ->assertJsonPath('message', 'Ya existe una persona con esa CURP.');
    }

    public function test_index_requires_permission(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $this->getJson('/api/teachers')->assertForbidden();
    }

    private function actingTeacherAdmin(): User
    {
        $user = User::factory()->create();
        foreach (['view teachers', 'create teachers', 'edit teachers', 'delete teachers'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(): array
    {
        return [
            ...$this->personFields(),
            ...$this->addressFields(),
            'employee' => 'A-001',
            'classroom' => '16',
            'status' => 'active',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function personFields(): array
    {
        return [
            'first_name' => 'ARACELI',
            'last_name' => 'VILLANUEVA',
            'second_last_name' => 'MARTINEZ',
            'curp' => 'VIMA850615MOCPZNA1',
            'birth_date' => '1985-06-15',
            'gender' => 'F',
            'phone' => '9511234567',
            'email' => 'araceli@example.com',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function addressFields(): array
    {
        return [
            'street_type' => 'Calle',
            'street_name' => 'Independencia',
            'house_number' => '10',
            'unit_number' => '',
            'neighborhood_type' => 'Colonia',
            'neighborhood_name' => 'Centro',
            'postal_code' => '68000',
            'city' => 'Oaxaca de Juárez',
            'state' => 'Oaxaca',
        ];
    }
}
