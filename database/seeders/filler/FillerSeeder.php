<?php

namespace Database\Seeders\filler;

use Illuminate\Database\Seeder;

class FillerSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->call([
            AcademicYearSeeder::class,
            GradeLevelSeeder::class,
            ClassGroupSeeder::class,
            ClassroomSeeder::class,
            SubjectSeeder::class,
        ]);
    }
}
