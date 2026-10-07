<?php

namespace Tests\Feature\Staff;

use App\Models\Profile;
use App\Models\Staff;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StaffPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_stores_photo_and_returns_it_on_detail(): void
    {
        Storage::fake('private');
        $this->actingEditor();
        $staff = $this->makeStaff();

        $this->post("/api/staff/{$staff->id}/photo", [
            'photo' => UploadedFile::fake()->image('foto.jpg', 80, 80),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('private')->assertExists("photos/staff/{$staff->id}/current/profile.jpg");

        $url = $this->getJson("/api/staff/{$staff->id}")
            ->assertOk()
            ->json('data.photos.profile_url');
        $this->assertIsString($url);
        $this->assertNotSame('', $url);
    }

    public function test_upload_requires_edit_permission(): void
    {
        Storage::fake('private');
        $user = User::factory()->create();
        Permission::findOrCreate('view staff', 'web');
        $user->givePermissionTo('view staff');
        Sanctum::actingAs($user);
        $staff = $this->makeStaff();

        $this->post("/api/staff/{$staff->id}/photo", [
            'photo' => UploadedFile::fake()->image('foto.jpg', 80, 80),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    private function actingEditor(): User
    {
        $user = User::factory()->create();
        foreach (['view staff', 'edit staff'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeStaff(): Staff
    {
        $profile = Profile::query()->create([
            'first_name' => 'ROSA',
            'last_name' => 'ADMIN',
            'national_id' => 'ADRO900101MOCPZNA2',
            'gender' => 'F',
        ]);

        return Staff::query()->create([
            'profile_id' => $profile->id,
            'position' => 'Secretaria',
            'status' => 'active',
        ]);
    }
}
