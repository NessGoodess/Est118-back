<?php

namespace Database\Seeders;

use App\Models\PreEnrollment;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class PreEnrollmentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        
        PreEnrollment::factory()
            ->count(100)
            ->create();
    }
}
