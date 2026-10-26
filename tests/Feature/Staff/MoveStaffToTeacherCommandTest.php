<?php

namespace Tests\Feature\Staff;

use App\Models\Profile;
use App\Models\Staff;
use App\Models\Teacher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MoveStaffToTeacherCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_moves_staff_row_and_photo_without_rewriting_the_profile(): void
    {
        Storage::fake('private');
        $staff = $this->makeStaff('ADRO900101MOCPZNA2');
        $profile = $staff->profile;
        $staffCreated = '2024-03-01 10:00:00';
        $staffUpdated = '2024-06-15 18:30:00';
        $profileUpdated = '2023-05-05 05:05:05';
        $cardUpdated = '2024-02-02 02:02:02';

        DB::table('staff')->where('id', $staff->id)->update([
            'position' => 'Secretaria',
            'department' => 'Administración',
            'status' => 'active',
            'created_at' => $staffCreated,
            'updated_at' => $staffUpdated,
        ]);
        DB::table('profiles')->where('id', $profile->id)->update([
            'profile_picture' => "staff/{$staff->id}/current/profile.jpg",
            'updated_at' => $profileUpdated,
        ]);
        Storage::disk('private')->put("photos/staff/{$staff->id}/current/profile.jpg", 'current');
        Storage::disk('private')->put("photos/staff/{$staff->id}/versions/20240101120000/profile.jpg", 'old');
        $printId = DB::table('credential_prints')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'student_id' => null,
            'profile_id' => $profile->id,
            'kind' => 'staff',
            'batch_uuid' => (string) Str::uuid(),
            'created_at' => $cardUpdated,
            'updated_at' => $cardUpdated,
        ]);

        $this->artisan('staff:move-to-teacher', ['curp' => 'adro900101mocpzna2'])
            ->assertSuccessful();

        $this->assertNull(Staff::query()->find($staff->id));
        $teacher = Teacher::query()->where('profile_id', $profile->id)->first();
        $this->assertNotNull($teacher);
        $this->assertSame('Secretaria', $teacher->position);
        $this->assertSame('Administración', $teacher->department);
        $this->assertSame('active', $teacher->getRawOriginal('status'));
        $this->assertSame($staffCreated, $teacher->getRawOriginal('created_at'));
        $this->assertSame($staffUpdated, $teacher->getRawOriginal('updated_at'));

        $fresh = $profile->fresh();
        $this->assertSame('ROSA', $fresh->first_name);
        $this->assertSame('PEREZ', $fresh->last_name);
        $this->assertSame('ADRO900101MOCPZNA2', $fresh->national_id);
        $this->assertSame($profileUpdated, $fresh->getRawOriginal('updated_at'));
        $this->assertSame("teachers/{$teacher->id}/current/profile.jpg", $fresh->profile_picture);

        Storage::disk('private')->assertExists("photos/teachers/{$teacher->id}/current/profile.jpg");
        Storage::disk('private')->assertExists("photos/teachers/{$teacher->id}/versions/20240101120000/profile.jpg");
        Storage::disk('private')->assertMissing("photos/staff/{$staff->id}/current/profile.jpg");

        $card = DB::table('credential_prints')->where('id', $printId)->first();
        $this->assertSame('teachers', $card->kind);
        $this->assertSame($cardUpdated, $card->updated_at);
    }

    public function test_dry_run_does_not_write(): void
    {
        Storage::fake('private');
        $staff = $this->makeStaff('ADRO900101MOCPZNA2');
        Storage::disk('private')->put("photos/staff/{$staff->id}/current/profile.jpg", 'current');

        $this->artisan('staff:move-to-teacher', [
            'curp' => 'ADRO900101MOCPZNA2',
            '--dry-run' => true,
        ])->assertSuccessful();

        $this->assertNotNull(Staff::query()->find($staff->id));
        $this->assertNull(Teacher::query()->where('profile_id', $staff->profile_id)->first());
        Storage::disk('private')->assertExists("photos/staff/{$staff->id}/current/profile.jpg");
    }

    public function test_refuses_when_the_person_is_already_a_teacher(): void
    {
        $staff = $this->makeStaff('ADRO900101MOCPZNA2');
        Teacher::query()->create([
            'profile_id' => $staff->profile_id,
            'status' => 'active',
        ]);

        $this->artisan('staff:move-to-teacher', ['curp' => 'ADRO900101MOCPZNA2'])
            ->assertFailed();

        $this->assertNotNull(Staff::query()->find($staff->id));
    }

    public function test_refuses_an_unknown_curp(): void
    {
        $this->artisan('staff:move-to-teacher', ['curp' => 'ADRO900101MOCPZNA2'])
            ->assertFailed();
    }

    private function makeStaff(string $curp): Staff
    {
        $profile = Profile::query()->create([
            'first_name' => 'ROSA',
            'last_name' => 'PEREZ',
            'national_id' => $curp,
            'gender' => 'F',
        ]);

        return Staff::query()->create([
            'profile_id' => $profile->id,
            'position' => 'Secretaria',
            'department' => 'Administración',
            'status' => 'active',
        ]);
    }
}
