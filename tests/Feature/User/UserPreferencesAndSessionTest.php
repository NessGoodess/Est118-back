<?php

namespace Tests\Feature\User;

use App\Models\Profile;
use App\Models\Teacher;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UserPreferencesAndSessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_resource_includes_preferences_defaults_and_null_avatar(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.preferences.theme', 'light')
            ->assertJsonPath('data.preferences.sidebar_mode', 'expanded')
            ->assertJsonPath('data.preferences.idle_logout_minutes', 60)
            ->assertJsonPath('data.preferences.shell_colors.sidebar', 'default')
            ->assertJsonPath('data.preferences.shell_colors.main', 'default')
            ->assertJsonPath('data.avatar_url', null)
            ->assertJsonPath('data.profile', null);
    }

    public function test_user_resource_includes_linked_profile_kind(): void
    {
        $user = User::factory()->create();
        $profile = Profile::query()->create([
            'user_id' => $user->id,
            'first_name' => 'ANA',
            'last_name' => 'LOPEZ',
            'national_id' => 'LODA900101MOCPZN09',
            'gender' => 'F',
            'email' => 'ana@example.com',
        ]);
        Teacher::query()->create([
            'profile_id' => $profile->id,
            'status' => 'active',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.profile.kind', 'teacher')
            ->assertJsonPath('data.profile.first_name', 'ANA')
            ->assertJsonPath('data.avatar_url', null);
    }

    public function test_patch_preferences_updates_values(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/preferences', [
            'theme' => 'dark',
            'sidebar_mode' => 'compact',
            'idle_logout_minutes' => 30,
            'shell_colors' => [
                'sidebar' => 'brand',
                'main' => 'primary',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.theme', 'dark')
            ->assertJsonPath('data.sidebar_mode', 'compact')
            ->assertJsonPath('data.idle_logout_minutes', 30)
            ->assertJsonPath('data.shell_colors.sidebar', 'brand')
            ->assertJsonPath('data.shell_colors.main', 'primary');

        $this->assertDatabaseHas('user_preferences', [
            'user_id' => $user->id,
            'theme' => 'dark',
            'sidebar_mode' => 'compact',
            'idle_logout_minutes' => 30,
        ]);

        $this->getJson('/api/user')
            ->assertOk()
            ->assertJsonPath('data.preferences.theme', 'dark')
            ->assertJsonPath('data.preferences.shell_colors.sidebar', 'brand')
            ->assertJsonPath('data.preferences.shell_colors.main', 'primary');
    }

    public function test_patch_shell_colors_merges_partial_and_rejects_invalid(): void
    {
        $user = User::factory()->create();
        UserPreference::query()->create([
            'user_id' => $user->id,
            ...UserPreference::defaults(),
            'shell_colors' => [
                'sidebar' => 'brand',
                'main' => 'muted',
            ],
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/preferences', [
            'shell_colors' => [
                'main' => 'primary',
            ],
        ])
            ->assertOk()
            ->assertJsonPath('data.shell_colors.sidebar', 'brand')
            ->assertJsonPath('data.shell_colors.main', 'primary');

        $this->patchJson('/api/user/preferences', [
            'shell_colors' => [
                'sidebar' => 'neon',
            ],
        ])->assertStatus(422);
    }

    public function test_patch_preferences_rejects_out_of_bounds_idle(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/preferences', [
            'idle_logout_minutes' => 5,
        ])->assertStatus(422);

        $this->patchJson('/api/user/preferences', [
            'idle_logout_minutes' => 999,
        ])->assertStatus(422);
    }

    public function test_patch_preferences_allows_null_idle(): void
    {
        $user = User::factory()->create();
        UserPreference::query()->create([
            'user_id' => $user->id,
            ...UserPreference::defaults(),
        ]);
        Sanctum::actingAs($user);

        $this->patchJson('/api/user/preferences', [
            'idle_logout_minutes' => null,
        ])
            ->assertOk()
            ->assertJsonPath('data.idle_logout_minutes', null);
    }
}
