<?php

namespace Tests\Feature\Staff;

use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StaffCrudTest extends TestCase
{
    use RefreshDatabase;

    public function test_store_show_update_and_deactivate_staff(): void
    {
        $this->actingStaffAdmin();

        $response = $this->postJson('/api/staff', $this->payload());
        $response->assertCreated()
            ->assertJsonPath('data.job.position', 'Secretaria')
            ->assertJsonPath('data.personal.first_name', 'ROSA');

        $id = $response->json('data.id');
        $this->assertSame('PEREZ DIAZ', Staff::query()->find($id)?->profile?->last_name);

        $this->patchJson("/api/staff/{$id}", [
            'job' => [
                'position' => 'Administradora',
                'department' => 'Dirección',
            ],
        ])->assertOk()->assertJsonPath('data.job.position', 'Administradora');

        $this->patchJson("/api/staff/{$id}/status", ['status' => 'inactive'])
            ->assertOk()
            ->assertJsonPath('data.job.status', 'inactive');

        $this->getJson('/api/staff?status=active')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/staff')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_duplicate_curp_is_rejected(): void
    {
        $this->actingStaffAdmin();
        $this->postJson('/api/staff', $this->payload())->assertCreated();
        $this->postJson('/api/staff', $this->payload())->assertStatus(422);
    }

    public function test_position_is_required(): void
    {
        $this->actingStaffAdmin();
        $payload = $this->payload();
        unset($payload['position']);
        $this->postJson('/api/staff', $payload)->assertStatus(422);
    }

    private function actingStaffAdmin(): User
    {
        $user = User::factory()->create();
        foreach (['view staff', 'create staff', 'edit staff', 'delete staff'] as $permission) {
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
            'first_name' => 'ROSA',
            'last_name' => 'PEREZ',
            'second_last_name' => 'DIAZ',
            'curp' => 'PEDR900101MOCPZNA2',
            'birth_date' => '1990-01-01',
            'gender' => 'F',
            'phone' => '9517654321',
            'email' => 'rosa.staff@example.com',
            'street_type' => 'Calle',
            'street_name' => 'Juarez',
            'house_number' => '5',
            'neighborhood_type' => 'Colonia',
            'neighborhood_name' => 'Reforma',
            'postal_code' => '68010',
            'city' => 'Oaxaca de Juárez',
            'state' => 'Oaxaca',
            'position' => 'Secretaria',
            'department' => 'Administración',
        ];
    }
}
