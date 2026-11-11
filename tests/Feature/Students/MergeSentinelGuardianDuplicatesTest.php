<?php

namespace Tests\Feature\Students;

use App\Models\Guardian;
use App\Models\Profile;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MergeSentinelGuardianDuplicatesTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_write_and_apply_merges_same_phone_only(): void
    {
        $real = $this->guardian('ROSA', 'LOPEZ', 'LOPR850101MDFPRS09', '9511111111');
        $sentinelSamePhone = $this->guardian('ROSA', 'LOPEZ', 'NO_ESPECIFICADO_1', '9511111111');
        $sentinelOtherPhone = $this->guardian('ANA', 'PEREZ', 'NO_ESPECIFICADO_2', '9512222222');
        $realOtherName = $this->guardian('ANA', 'PEREZ', 'PEAA900101MDFRNS09', '9513333333');

        $childA = $this->student('A', 'LOPEZ');
        $childB = $this->student('B', 'PEREZ');
        $childA->guardians()->attach($sentinelSamePhone->id, [
            'relationship' => 'Madre',
            'is_primary' => true,
            'is_secondary' => false,
            'sort_order' => 0,
        ]);
        $childB->guardians()->attach($sentinelOtherPhone->id, [
            'relationship' => 'Madre',
            'is_primary' => true,
            'is_secondary' => false,
            'sort_order' => 0,
        ]);

        $this->artisan('guardians:merge-sentinel-duplicates')
            ->assertSuccessful();

        $this->assertDatabaseHas('guardians', ['id' => $sentinelSamePhone->id]);
        $this->assertDatabaseHas('guardians', ['id' => $sentinelOtherPhone->id]);

        $this->artisan('guardians:merge-sentinel-duplicates', ['--apply' => true])
            ->assertSuccessful();

        $this->assertDatabaseMissing('guardians', ['id' => $sentinelSamePhone->id]);
        $this->assertTrue($childA->fresh()->guardians()->whereKey($real->id)->exists());
        $this->assertDatabaseHas('guardians', ['id' => $sentinelOtherPhone->id]);
        $this->assertTrue($childB->fresh()->guardians()->whereKey($sentinelOtherPhone->id)->exists());
        $this->assertDatabaseHas('guardians', ['id' => $realOtherName->id]);
    }

    private function student(string $first, string $last): Student
    {
        $profile = Profile::query()->create([
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'F',
        ]);

        return Student::query()->create(['profile_id' => $profile->id]);
    }

    private function guardian(string $first, string $last, string $curp, string $phone): Guardian
    {
        $profile = Profile::query()->create([
            'national_id' => $curp,
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'F',
            'phone_number' => $phone,
        ]);

        return Guardian::query()->create(['profile_id' => $profile->id]);
    }
}
