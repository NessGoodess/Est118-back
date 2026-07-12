<?php

namespace App\Services\School;

use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\GradeLevel;
use Illuminate\Support\Facades\DB;

class AcademicYearService
{
    /**
     * Create academic year and optionally seed class groups (A–H per grade).
     */
    public function create(array $data): AcademicYear
    {
        return DB::transaction(function () use ($data) {
            $description = $data['description']
                ?? "Año escolar {$data['year_start']}-{$data['year_end']}";

            $year = AcademicYear::create([
                'year_start' => $data['year_start'],
                'year_end' => $data['year_end'],
                'description' => $description,
                'is_active' => false,
            ]);

            if ($data['generate_class_groups'] ?? true) {
                $this->generateClassGroups($year);
            }

            return $year->fresh();
        });
    }

    public function generateClassGroups(AcademicYear $year): int
    {
        $gradeLevels = GradeLevel::all();
        $groupNames = ['A', 'B', 'C', 'D', 'E', 'F', 'G', 'H'];
        $created = 0;

        foreach ($gradeLevels as $gradeLevel) {
            foreach ($groupNames as $name) {
                $exists = ClassGroup::query()
                    ->where('academic_year_id', $year->id)
                    ->where('grade_level_id', $gradeLevel->id)
                    ->where('name', $name)
                    ->exists();

                if ($exists) {
                    continue;
                }

                ClassGroup::create([
                    'academic_year_id' => $year->id,
                    'grade_level_id' => $gradeLevel->id,
                    'name' => $name,
                ]);
                $created++;
            }
        }

        return $created;
    }

    public function activate(AcademicYear $year): AcademicYear
    {
        return DB::transaction(function () use ($year) {
            AcademicYear::query()
                ->where('id', '!=', $year->id)
                ->update(['is_active' => false]);

            $year->update(['is_active' => true]);

            return $year->fresh();
        });
    }
}
