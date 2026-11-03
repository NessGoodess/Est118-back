<?php

namespace Tests\Feature\Access;

use App\Models\Profile;
use App\Models\ProfileUserLinkAudit;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ProfileAccessLinkTest extends TestCase
{
    use RefreshDatabase;

    public function test_can_link_existing_user_by_email_match(): void
    {
        $admin = $this->actingLinker();
        $user = User::factory()->create(['email' => 'link.me@example.com', 'name' => 'Link Me']);
        $teacher = $this->makeTeacher('link.me@example.com');

        $this->getJson("/api/teachers/{$teacher->id}/access-link-match")
            ->assertOk()
            ->assertJsonPath('data.user.id', $user->id);

        $this->postJson("/api/teachers/{$teacher->id}/access-link", [
            'user_id' => $user->id,
            'confirm' => true,
        ])->assertOk();

        $this->assertSame($user->id, $teacher->fresh()->profile->user_id);
        $this->assertDatabaseHas('profile_user_link_audits', [
            'profile_id' => $teacher->profile_id,
            'user_id' => $user->id,
            'linked_by' => $admin->id,
            'action' => ProfileUserLinkAudit::ACTION_LINK,
            'email_matched' => 1,
        ]);
    }

    public function test_rejects_link_when_user_already_has_profile(): void
    {
        $this->actingLinker();
        $user = User::factory()->create(['email' => 'taken@example.com']);
        $other = $this->makeTeacher('other@example.com', ['user_id' => $user->id]);
        $teacher = $this->makeTeacher('taken@example.com', [], 'LODA900101MOCPZN88');

        $this->postJson("/api/teachers/{$teacher->id}/access-link", [
            'user_id' => $user->id,
            'confirm' => true,
        ])->assertStatus(422);

        $this->assertNull($teacher->fresh()->profile->user_id);
        $this->assertSame($user->id, $other->fresh()->profile->user_id);
    }

    public function test_command_dry_run_lists_candidates(): void
    {
        User::factory()->create(['email' => 'cmd@example.com', 'name' => 'Cmd User']);
        $this->makeTeacher('cmd@example.com');

        $this->artisan('profiles:link-users-by-email', ['--kind' => 'teachers'])
            ->assertSuccessful()
            ->expectsOutputToContain('Dry-run');
    }

    private function actingLinker(): User
    {
        $user = User::factory()->create();
        foreach (['view teachers', 'invite teacher accounts'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    /**
     * @param  array<string, mixed>  $profileOverrides
     */
    private function makeTeacher(string $email, array $profileOverrides = [], ?string $curp = null): Teacher
    {
        $profile = Profile::query()->create([
            'user_id' => $profileOverrides['user_id'] ?? null,
            'first_name' => 'ANA',
            'last_name' => 'LOPEZ',
            'national_id' => $curp ?? ('LODA900101MOCPZN'.random_int(10, 99)),
            'gender' => 'F',
            'email' => $email,
        ]);

        return Teacher::query()->create([
            'profile_id' => $profile->id,
            'status' => 'active',
        ]);
    }
}
