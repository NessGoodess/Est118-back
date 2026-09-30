<?php

namespace Tests\Feature\Students;

use App\Models\Profile;
use App\Models\Student;
use App\Models\User;
use App\Services\StudentPhotoService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StudentPhotoUploadIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    public function test_same_upload_id_stores_photo_once(): void
    {
        Sanctum::actingAs($this->photoManager());
        $student = $this->student();

        $this->mock(StudentPhotoService::class, function ($mock) {
            $mock->shouldReceive('storeStudentPhoto')->once()->andReturn($this->storedResult());
        });

        $uploadId = (string) Str::uuid();

        $this->postPhoto($student->id, $uploadId)->assertOk()->assertJsonPath('success', true);
        $this->postPhoto($student->id, $uploadId)->assertOk()->assertJsonPath('success', true);
    }

    public function test_different_upload_ids_store_each_photo(): void
    {
        Sanctum::actingAs($this->photoManager());
        $student = $this->student();

        $this->mock(StudentPhotoService::class, function ($mock) {
            $mock->shouldReceive('storeStudentPhoto')->twice()->andReturn($this->storedResult());
        });

        $this->postPhoto($student->id, (string) Str::uuid())->assertOk();
        $this->postPhoto($student->id, (string) Str::uuid())->assertOk();
    }

    public function test_without_upload_id_keeps_previous_behavior(): void
    {
        Sanctum::actingAs($this->photoManager());
        $student = $this->student();

        $this->mock(StudentPhotoService::class, function ($mock) {
            $mock->shouldReceive('storeStudentPhoto')->twice()->andReturn($this->storedResult());
        });

        $this->postPhoto($student->id)->assertOk();
        $this->postPhoto($student->id)->assertOk();
    }

    public function test_rejects_invalid_upload_id(): void
    {
        Sanctum::actingAs($this->photoManager());
        $student = $this->student();

        $this->postPhoto($student->id, 'not-a-uuid')
            ->assertUnprocessable()
            ->assertJsonValidationErrors('upload_id');
    }

    private function postPhoto(int $studentId, ?string $uploadId = null)
    {
        $payload = ['photo' => UploadedFile::fake()->image('foto.jpg', 400, 400)];
        if ($uploadId !== null) {
            $payload['upload_id'] = $uploadId;
        }

        return $this->post("/api/students/{$studentId}/photo", $payload, ['Accept' => 'application/json']);
    }

    /**
     * @return array{filename:string, path_original:string, path_profile:string, path_thumb:string}
     */
    private function storedResult(): array
    {
        return [
            'filename' => 'students/1/current/profile.jpg',
            'path_original' => 'photos/students/1/current/original.jpg',
            'path_profile' => 'photos/students/1/current/profile.jpg',
            'path_thumb' => 'photos/students/1/current/thumb.jpg',
        ];
    }

    private function student(): Student
    {
        $profile = Profile::create([
            'first_name' => 'Ana',
            'last_name' => 'Pérez',
            'national_id' => 'PEAA010101MNEXXX01',
            'gender' => 'F',
        ]);

        return Student::create(['profile_id' => $profile->id]);
    }

    private function photoManager(): User
    {
        $user = User::factory()->create();
        Permission::findOrCreate('manage student photos', 'web');
        $user->givePermissionTo('manage student photos');

        return $user;
    }
}
