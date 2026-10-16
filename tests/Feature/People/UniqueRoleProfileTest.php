<?php

namespace Tests\Feature\People;

use App\Models\Profile;
use App\Models\Staff;
use App\Models\Teacher;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UniqueRoleProfileTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_profile_can_only_be_one_teacher(): void
    {
        $profile = $this->profile();
        Teacher::query()->create(['profile_id' => $profile->id]);

        $this->expectException(QueryException::class);
        Teacher::query()->create(['profile_id' => $profile->id]);
    }

    public function test_a_profile_can_only_be_one_staff_member(): void
    {
        $profile = $this->profile();
        Staff::query()->create(['profile_id' => $profile->id, 'position' => 'Secretaria']);

        $this->expectException(QueryException::class);
        Staff::query()->create(['profile_id' => $profile->id, 'position' => 'Secretaria']);
    }

    public function test_the_same_profile_can_be_teacher_and_staff(): void
    {
        $profile = $this->profile();
        Teacher::query()->create(['profile_id' => $profile->id]);
        Staff::query()->create(['profile_id' => $profile->id, 'position' => 'Coordinación']);

        $this->assertSame(1, Teacher::query()->where('profile_id', $profile->id)->count());
        $this->assertSame(1, Staff::query()->where('profile_id', $profile->id)->count());
    }

    private function profile(): Profile
    {
        return Profile::query()->create([
            'national_id' => null,
            'first_name' => 'ROSA',
            'last_name' => 'PEREZ',
        ]);
    }
}
