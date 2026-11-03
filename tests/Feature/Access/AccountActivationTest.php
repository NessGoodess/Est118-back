<?php

namespace Tests\Feature\Access;

use App\Models\AccountActivation;
use App\Models\User;
use App\Notifications\AccountActivationNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountActivationTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_register_without_password_sends_activation(): void
    {
        Notification::fake();
        Role::findOrCreate('user', 'web');
        $admin = User::factory()->create();
        Permission::findOrCreate('create users', 'web');
        $admin->givePermissionTo('create users');
        Sanctum::actingAs($admin);

        $this->postJson('/register', [
            'name' => 'Nuevo Usuario',
            'email' => 'nuevo@example.com',
            'roles' => ['user'],
        ])
            ->assertCreated()
            ->assertJsonPath('user.email', 'nuevo@example.com');

        $user = User::query()->where('email', 'nuevo@example.com')->first();
        $this->assertNotNull($user);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(AccountActivation::query()->where('user_id', $user->id)->whereNull('accepted_at')->exists());
        Notification::assertSentOnDemand(AccountActivationNotification::class);
    }

    public function test_accept_activation_sets_password_and_verifies(): void
    {
        $user = User::factory()->create([
            'email' => 'activar@example.com',
            'password' => Hash::make('RandomPlaceholder64charsXXXXXXXXXXXXXXXXXXXXXXXX'),
            'email_verified_at' => null,
        ]);
        $plain = 'activation-token-'.bin2hex(random_bytes(8));
        AccountActivation::query()->create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDay(),
        ]);

        $this->postJson('/api/account-activations/'.$plain.'/accept', [
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
        ])->assertCreated();

        $user->refresh();
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue(Hash::check('Secret123!', $user->password));
        $this->assertNotNull($user->preference);
        $this->assertNotNull(AccountActivation::query()->where('user_id', $user->id)->value('accepted_at'));
    }
}
