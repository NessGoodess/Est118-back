<?php

namespace Tests\Feature\Students;

use App\Models\AcademicYear;
use App\Models\Guardian;
use App\Models\Profile;
use App\Models\Student;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class StudentPhotoHistoryAndGuardianTest extends TestCase
{
    use RefreshDatabase;

    public function test_photo_history_lists_current_and_archived_photos_by_cycle(): void
    {
        Sanctum::actingAs($this->userWith('view student photos'));
        Storage::fake('private');

        AcademicYear::factory()->create([
            'year_start' => '2025',
            'year_end' => '2026',
            'starts_on' => '2025-08-01',
            'ends_on' => '2026-07-31',
            'is_active' => false,
        ]);
        AcademicYear::factory()->create([
            'year_start' => '2026',
            'year_end' => '2027',
            'starts_on' => '2026-08-01',
            'ends_on' => '2027-07-31',
            'is_active' => true,
        ]);

        $student = $this->student('Ana', 'López');
        $disk = Storage::disk('private');
        $disk->put("photos/students/{$student->id}/current/profile.jpg", 'current-bytes');
        $disk->put("photos/students/{$student->id}/current/thumb.jpg", 'current-thumb');
        $disk->put('photos/students/'.$student->id.'/versions/20250915120000/profile.jpg', 'old-bytes');
        $disk->put('photos/students/'.$student->id.'/versions/20250915120000/thumb.jpg', 'old-thumb');

        $rows = $this->getJson("/api/students/{$student->id}/photo-history")
            ->assertOk()
            ->json('data');

        $currentYear = AcademicYear::query()
            ->whereDate('starts_on', '<=', now()->toDateString())
            ->whereDate('ends_on', '>=', now()->toDateString())
            ->first();

        $this->assertCount(2, $rows);
        $this->assertTrue($rows[0]['is_current']);
        $this->assertSame($currentYear->year_start.'-'.$currentYear->year_end, $rows[0]['academic_year']);
        $this->assertSame('20250915120000', $rows[1]['version']);
        $this->assertSame('2025-2026', $rows[1]['academic_year']);
        $this->assertNotNull($rows[1]['profile_url']);

        $archived = URL::temporarySignedRoute('private.image', now()->addMinutes(5), [
            'id' => $student->id,
            'size' => 'profile',
            'version' => '20250915120000',
        ]);
        $this->get($archived)->assertOk();

        $escaped = URL::temporarySignedRoute('private.image', now()->addMinutes(5), [
            'id' => $student->id,
            'size' => 'profile',
            'version' => '../secrets',
        ]);
        $this->get($escaped)->assertNotFound();
    }

    public function test_renewing_a_photo_archives_the_previous_one(): void
    {
        Storage::fake('private');
        $student = $this->student('Ana', 'López');
        $service = app(\App\Services\StudentPhotoService::class);

        Carbon::setTestNow('2026-09-01 10:00:00');
        $service->storeStudentPhoto($student, UploadedFile::fake()->image('first.jpg', 80, 80));

        Carbon::setTestNow('2026-10-01 16:00:00');
        $service->storeStudentPhoto($student->fresh(), UploadedFile::fake()->image('second.jpg', 80, 80));
        Carbon::setTestNow();

        Storage::disk('private')->assertExists(
            "photos/students/{$student->id}/versions/20261001160000/profile.jpg"
        );
        Storage::disk('private')->assertExists(
            "photos/students/{$student->id}/current/profile.jpg"
        );
    }

    public function test_guardian_update_derives_data_from_curp_and_keeps_other_relationship(): void
    {
        Sanctum::actingAs($this->userWith('edit students', 'view students'));

        $ana = $this->student('Ana', 'López');
        $luis = $this->student('Luis', 'Mora');
        $guardian = $this->guardian('Rosa', 'López');
        $ana->guardians()->attach($guardian->id, ['relationship' => 'Madre']);
        $luis->guardians()->attach($guardian->id, ['relationship' => 'Tía']);

        $response = $this->patchJson("/api/students/{$ana->id}/guardians/{$guardian->id}", [
            'first_name' => 'Rosa María',
            'last_name' => 'López',
            'national_id' => 'GALM090101MDFRRR01',
            'relationship' => 'Mamá',
            'email' => 'rosa@example.com',
            'phone' => '7221234567',
        ])->assertOk();

        $saved = collect($response->json('data.guardians'))->firstWhere('id', $guardian->id);
        $this->assertSame('Rosa María', $saved['first_name']);
        $this->assertSame('GALM090101MDFRRR01', $saved['national_id']);
        $this->assertSame('Mamá', $saved['relationship']);
        $this->assertSame('rosa@example.com', $saved['email']);
        $this->assertSame('2009-01-01', $saved['birth_date']);
        $this->assertSame('F', $saved['gender']);
        $this->assertSame(2009, $saved['birth_year']);
        $this->assertSame(Carbon::parse('2009-01-01')->age, $saved['age']);

        $this->assertSame(
            'Tía',
            $luis->guardians()->whereKey($guardian->id)->first()->pivot->relationship
        );

        $cleared = $this->patchJson("/api/students/{$ana->id}/guardians/{$guardian->id}", [
            'first_name' => 'Rosa María',
            'last_name' => 'López',
            'national_id' => '',
            'relationship' => 'Mamá',
            'email' => '',
            'phone' => '',
        ])->assertOk()->json('data.guardians.0');

        $this->assertNull($cleared['national_id']);
        $this->assertNull($cleared['birth_date']);
        $this->assertNull($cleared['gender']);
        $this->assertNull($cleared['age']);

        $this->patchJson("/api/students/{$ana->id}/guardians/{$guardian->id}", [
            'first_name' => 'Rosa',
            'last_name' => 'López',
            'national_id' => 'NO-ES-CURP',
            'relationship' => 'Mamá',
        ])->assertUnprocessable()->assertJsonValidationErrors('national_id');
    }

    public function test_guardian_update_allows_empty_curp_and_relationship(): void
    {
        Sanctum::actingAs($this->userWith('edit students', 'view students'));

        $student = $this->student('Ana', 'López');
        $guardian = $this->guardian('Rosa', 'López');
        $student->guardians()->attach($guardian->id, ['relationship' => 'Madre']);

        $saved = $this->patchJson("/api/students/{$student->id}/guardians/{$guardian->id}", [
            'first_name' => 'Rosa',
            'last_name' => 'López',
            'national_id' => '',
            'relationship' => '',
            'email' => '',
            'phone' => '',
        ])->assertOk()->json('data.guardians.0');

        $this->assertNull($saved['national_id']);
        $this->assertNull($saved['relationship']);
    }

    private function student(string $firstName, string $lastName): Student
    {
        $profile = Profile::query()->create([
            'national_id' => strtoupper(fake()->unique()->bothify('????######H?????##')),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => 'O',
        ]);

        return Student::query()->create(['profile_id' => $profile->id]);
    }

    private function guardian(string $firstName, string $lastName): Guardian
    {
        $profile = Profile::query()->create([
            'national_id' => strtoupper(fake()->unique()->bothify('????######H?????##')),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => 'F',
        ]);

        return Guardian::query()->create(['profile_id' => $profile->id]);
    }

    private function userWith(string ...$permissions): User
    {
        $user = User::factory()->create();
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        $user->givePermissionTo($permissions);

        return $user;
    }
}
