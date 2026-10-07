<?php

namespace Tests\Feature\Teachers;

use App\Models\Profile;
use App\Models\Teacher;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TeacherPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_upload_stores_photo_and_archives_the_previous_one(): void
    {
        Storage::fake('private');
        $this->actingEditor();
        $teacher = $this->makeTeacher();

        $this->post("/api/teachers/{$teacher->id}/photo", [
            'photo' => UploadedFile::fake()->image('first.jpg', 80, 80),
        ], ['Accept' => 'application/json'])
            ->assertOk()
            ->assertJsonPath('success', true);

        Storage::disk('private')->assertExists("photos/teachers/{$teacher->id}/current/profile.jpg");

        $this->post("/api/teachers/{$teacher->id}/photo", [
            'photo' => UploadedFile::fake()->image('second.jpg', 80, 80),
        ], ['Accept' => 'application/json'])->assertOk();

        $history = $this->getJson("/api/teachers/{$teacher->id}/photo-history")
            ->assertOk()
            ->json('data');

        $this->assertCount(2, $history);
        $this->assertTrue($history[0]['is_current']);
        $this->assertFalse($history[1]['is_current']);

        $detail = $this->getJson("/api/teachers/{$teacher->id}")->assertOk();
        $this->assertNotNull($detail->json('data.photos.profile_url'));
    }

    public function test_upload_requires_edit_permission(): void
    {
        Storage::fake('private');
        $user = User::factory()->create();
        Permission::findOrCreate('view teachers', 'web');
        $user->givePermissionTo('view teachers');
        Sanctum::actingAs($user);
        $teacher = $this->makeTeacher();

        $this->post("/api/teachers/{$teacher->id}/photo", [
            'photo' => UploadedFile::fake()->image('foto.jpg', 80, 80),
        ], ['Accept' => 'application/json'])->assertForbidden();
    }

    private function actingEditor(): User
    {
        $user = User::factory()->create();
        foreach (['view teachers', 'edit teachers'] as $permission) {
            Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }
        Sanctum::actingAs($user);

        return $user;
    }

    private function makeTeacher(): Teacher
    {
        $profile = Profile::query()->create([
            'first_name' => 'ANA',
            'last_name' => 'DOCENTE',
            'national_id' => 'DOAN800101MOCPZNA8',
            'gender' => 'F',
        ]);

        return Teacher::query()->create([
            'profile_id' => $profile->id,
            'status' => 'active',
        ]);
    }
}
