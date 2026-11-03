<?php

namespace Tests\Feature\User;

use App\Models\Profile;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class UserSoftDeleteUnlinksProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_soft_deleting_user_clears_profile_link_and_staff_detail_has_no_account(): void
    {
        Permission::findOrCreate('view staff', 'web');
        Permission::findOrCreate('delete users', 'web');

        $admin = User::factory()->create();
        $admin->givePermissionTo(['view staff', 'delete users']);

        $account = User::factory()->create(['email' => 'staff@example.com']);
        $profile = Profile::query()->create([
            'user_id' => $account->id,
            'first_name' => 'LUIS',
            'last_name' => 'PEREZ',
            'national_id' => 'PEXL900101HOCRRS'.random_int(10, 99),
            'gender' => 'M',
            'email' => 'staff@example.com',
        ]);
        $staff = Staff::query()->create([
            'profile_id' => $profile->id,
            'position' => 'Administrativo',
            'status' => 'active',
        ]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/staff/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('data.access_account.has_user', true)
            ->assertJsonPath('data.access_account.user_id', $account->id);

        $this->deleteJson("/api/users/{$account->id}")->assertOk();

        $this->assertSoftDeleted('users', ['id' => $account->id]);
        $this->assertDatabaseHas('profiles', [
            'id' => $profile->id,
            'user_id' => null,
        ]);

        $this->getJson("/api/staff/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('data.access_account.has_user', false)
            ->assertJsonPath('data.access_account.user_id', null);
    }

    public function test_stale_user_id_to_trashed_user_is_healed_on_detail(): void
    {
        Permission::findOrCreate('view staff', 'web');
        $admin = User::factory()->create();
        $admin->givePermissionTo('view staff');

        $account = User::factory()->create(['email' => 'orphan@example.com']);
        $profile = Profile::query()->create([
            'user_id' => $account->id,
            'first_name' => 'ANA',
            'last_name' => 'ORPHAN',
            'national_id' => 'ORAA900101MOCPZN'.random_int(10, 99),
            'gender' => 'F',
            'email' => 'orphan@example.com',
        ]);
        $staff = Staff::query()->create([
            'profile_id' => $profile->id,
            'position' => 'Administrativo',
            'status' => 'active',
        ]);

        // Simulate legacy soft-delete that left the FK behind.
        $account->delete();
        Profile::query()->whereKey($profile->id)->update(['user_id' => $account->id]);

        Sanctum::actingAs($admin);

        $this->getJson("/api/staff/{$staff->id}")
            ->assertOk()
            ->assertJsonPath('data.access_account.has_user', false)
            ->assertJsonPath('data.access_account.user_id', null);

        $this->assertDatabaseHas('profiles', [
            'id' => $profile->id,
            'user_id' => null,
        ]);
    }
}
