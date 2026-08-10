<?php

namespace App\Services\School;

use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\GradeLevel;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AcademicYearService
{
    /**
     * Create academic year and optionally seed class groups (A–H per grade).
     */
    public function create(array $data): AcademicYear
    {
        return DB::transaction(function () use ($data) {
            $startsOn = Carbon::parse($data['starts_on'])->toDateString();
            $endsOn = Carbon::parse($data['ends_on'])->toDateString();

            $yearStart = (string) ($data['year_start'] ?? Carbon::parse($startsOn)->year);
            $yearEnd = (string) ($data['year_end'] ?? Carbon::parse($endsOn)->year);
            $description = $data['description']
                ?? "Año escolar {$yearStart}-{$yearEnd}";

            $year = AcademicYear::create([
                'year_start' => $yearStart,
                'year_end' => $yearEnd,
                'starts_on' => $startsOn,
                'ends_on' => $endsOn,
                'description' => $description,
                'is_active' => false,
            ]);

            if ($data['generate_class_groups'] ?? true) {
                $this->generateClassGroups($year);
            }

            return $year->fresh();
        });
    }

    public function update(AcademicYear $year, array $data): AcademicYear
    {
        $payload = $data;

        if (isset($payload['starts_on'])) {
            $payload['starts_on'] = Carbon::parse($payload['starts_on'])->toDateString();
        }
        if (isset($payload['ends_on'])) {
            $payload['ends_on'] = Carbon::parse($payload['ends_on'])->toDateString();
        }

        $year->update($payload);

        return $year->fresh();
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
