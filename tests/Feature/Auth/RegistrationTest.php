<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_users_can_be_registered_without_admin_password(): void
    {
        Role::findOrCreate('user', 'web');
        $admin = User::factory()->create();
        Permission::findOrCreate('create users', 'web');
        $admin->givePermissionTo('create users');
        Sanctum::actingAs($admin);

        $response = $this->postJson('/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'roles' => ['user'],
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('users', [
            'email' => 'test@example.com',
        ]);
        $this->assertDatabaseHas('account_activations', [
            'user_id' => User::query()->where('email', 'test@example.com')->value('id'),
        ]);
    }
}
