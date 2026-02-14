<?php

namespace Database\Seeders\filler;

use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\GradeLevel;
use Illuminate\Database\Seeder;

class ClassGroupSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
         $academicYear = AcademicYear::where('is_active', 1)->first();

        if (!$academicYear) {
            throw new \Exception("No active academic year found. Please seed AcademicYearSeeder first.");
        }

        $gradeLevels = GradeLevel::all();

        $groupNames = ['A','B','C','D','E','F','G','H'];

        foreach ($gradeLevels as $gradeLevel) {
            foreach ($groupNames as $name) {
                ClassGroup::create([
                    'academic_year_id' => $academicYear->id,
                    'grade_level_id'   => $gradeLevel->id,
                    'name'             => $name,
                ]);
            }
        }
    }
}
