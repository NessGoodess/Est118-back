<?php

namespace Tests\Feature\Access;

use App\Models\Profile;
use App\Models\ProfileAccessInvite;
use App\Models\ProfileUserLinkAudit;
use App\Models\Teacher;
use App\Models\User;
use App\Notifications\ProfileAccessInviteNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProfileAccessInviteTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_invite_teacher_and_accept_creates_linked_user(): void
    {
        Notification::fake();
        Role::findOrCreate('teacher', 'web');
        $this->actingInviteAdmin();
        $teacher = $this->makeTeacher();

        $this->postJson("/api/teachers/{$teacher->id}/access-invite")
            ->assertCreated()
            ->assertJsonPath('data.email', 'teacher@example.com');

        $invite = ProfileAccessInvite::query()->first();
        $this->assertNotNull($invite);
        Notification::assertSentOnDemand(ProfileAccessInviteNotification::class);

        $plain = 'test-token-'.bin2hex(random_bytes(16));
        $invite->update(['token_hash' => hash('sha256', $plain)]);

        $this->postJson('/api/access-invites/'.$plain.'/accept', [
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
        ])->assertCreated();

        $teacher->refresh()->load('profile');
        $user = User::query()->find($teacher->profile->user_id);
        $this->assertNotNull($user);
        $this->assertNotNull($user->email_verified_at);
        $this->assertTrue($user->hasRole('teacher'));
        $this->assertDatabaseHas('profile_user_link_audits', [
            'profile_id' => $teacher->profile_id,
            'user_id' => $user->id,
            'action' => ProfileUserLinkAudit::ACTION_INVITE_ACCEPT,
        ]);
    }

    public function test_accept_restores_soft_deleted_user(): void
    {
        Notification::fake();
        Role::findOrCreate('teacher', 'web');
        $this->actingInviteAdmin();
        $teacher = $this->makeTeacher();

        $trashed = User::factory()->create([
            'email' => 'teacher@example.com',
            'password' => Hash::make('OldPass123!'),
        ]);
        $trashed->delete();

        $this->postJson("/api/teachers/{$teacher->id}/access-invite")->assertCreated();
        $invite = ProfileAccessInvite::query()->first();
        $plain = 'restore-token-'.bin2hex(random_bytes(8));
        $invite->update(['token_hash' => hash('sha256', $plain)]);

        $this->postJson('/api/access-invites/'.$plain.'/accept', [
            'password' => 'NewPass123!',
            'password_confirmation' => 'NewPass123!',
        ])->assertCreated();

        $restored = User::query()->where('email', 'teacher@example.com')->first();
        $this->assertNotNull($restored);
        $this->assertSame($trashed->id, $restored->id);
        $this->assertTrue(Hash::check('NewPass123!', $restored->password));
        $this->assertSame($restored->id, $teacher->fresh()->profile->user_id);
        $this->assertDatabaseHas('profile_user_link_audits', [
            'action' => ProfileUserLinkAudit::ACTION_INVITE_RESTORE,
            'user_id' => $restored->id,
        ]);
    }

    public function test_accept_active_duplicate_returns_422_not_500(): void
    {
        Role::findOrCreate('teacher', 'web');
        $admin = User::factory()->create();
        $teacher = $this->makeTeacher();
        User::factory()->create(['email' => 'teacher@example.com']);

        $plain = 'dup-token-abc123';
        ProfileAccessInvite::query()->create([
            'profile_id' => $teacher->profile_id,
            'email' => 'teacher@example.com',
            'token_hash' => hash('sha256', $plain),
            'roles' => ['teacher'],
            'permissions' => null,
            'invited_by' => $admin->id,
            'expires_at' => now()->addDay(),
        ]);

        $this->postJson('/api/access-invites/'.$plain.'/accept', [
            'password' => 'Secret123!',
            'password_confirmation' => 'Secret123!',
        ])->assertStatus(422);
    }

    public function test_rejects_invite_when_profile_already_has_user(): void
    {
        Notification::fake();
        Role::findOrCreate('teacher', 'web');
        $this->actingInviteAdmin();
        $existing = User::factory()->create();
        $teacher = $this->makeTeacher(['user_id' => $existing->id]);

        $this->postJson("/api/teachers/{$teacher->id}/access-invite")->assertStatus(422);
    }

    public function test_rejects_admin_role_on_invite(): void
    {
        Notification::fake();
        Role::findOrCreate('admin', 'web');
        Role::findOrCreate('teacher', 'web');
        $this->actingInviteAdmin();
        $teacher = $this->makeTeacher();

        $this->postJson("/api/teachers/{$teacher->id}/access-invite", [
            'roles' => ['admin'],
        ])->assertStatus(422);
    }

    public function test_rejects_expired_token(): void
    {
        Role::findOrCreate('teacher', 'web');
        $admin = User::factory()->create();
        $teacher = $this->makeTeacher();
        $plain = 'expired-token-abc123';

        ProfileAccessInvite::query()->create([
            'profile_id' => $teacher->profile_id,
            'email' => 'teacher@example.com',
            'token_hash' => hash('sha256', $plain),
            'roles' => ['teacher'],
            'permissions' => null,
            'invited_by' => $admin->id,
            'expires_at' => now()->subDay(),
        ]);

        $this->getJson('/api/access-invites/'.$plain)->assertStatus(422);
    }

    public function test_rejects_taken_email_on_create_invite(): void
    {
        Notification::fake();
        Role::findOrCreate('teacher', 'web');
        $this->actingInviteAdmin();
        User::factory()->create(['email' => 'teacher@example.com']);
        $teacher = $this->makeTeacher();

        $this->postJson("/api/teachers/{$teacher->id}/access-invite")->assertStatus(422);
    }

    public function test_teacher_detail_exposes_link_candidate(): void
    {
        Permission::findOrCreate('view teachers', 'web');
        Permission::findOrCreate('invite teacher accounts', 'web');
        $admin = User::factory()->create();
        $admin->givePermissionTo(['view teachers', 'invite teacher accounts']);
        Sanctum::actingAs($admin);

        $candidate = User::factory()->create(['email' => 'teacher@example.com']);
        $teacher = $this->makeTeacher();

        $this->getJson("/api/teachers/{$teacher->id}")
            ->assertOk()
            ->assertJsonPath('data.access_account.link_candidate.id', $candidate->id)
            ->assertJsonPath('data.access_account.link_candidate.email', 'teacher@example.com');
    }

    public function test_invite_requires_permission(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $teacher = $this->makeTeacher();

        $this->postJson("/api/teachers/{$teacher->id}/access-invite")->assertForbidden();
    }

    private function actingInviteAdmin(): User
    {
        $user = User::factory()->create();
        foreach (['view teachers', 'invite teacher accounts', 'invite staff accounts'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $profileOverrides
     */
    private function makeTeacher(array $profileOverrides = []): Teacher
    {
        $profile = Profile::query()->create([
            'user_id' => $profileOverrides['user_id'] ?? null,
            'first_name' => 'ANA',
            'last_name' => 'LOPEZ',
            'second_last_name' => 'DIAZ',
            'national_id' => 'LODA900101MOCPZN'.random_int(10, 99),
            'birth_date' => '1990-01-01',
            'gender' => 'F',
            'email' => 'teacher@example.com',
            'phone_number' => '9511111111',
        ]);

        return Teacher::query()->create([
            'profile_id' => $profile->id,
            'employee' => 'T-100',
            'status' => 'active',
        ]);
    }
}
