<?php

namespace App\Services\Teachers;

use App\Models\AcademicYear;
use App\Models\SchoolClass;
use App\Models\Teacher;
use Illuminate\Database\Eloquent\Collection;
use RuntimeException;

class TeacherClassService
{
    /**
     * @return Collection<int, SchoolClass>
     */
    public function availableClasses(?int $yearId = null): Collection
    {
        $yearId ??= AcademicYear::query()->where('is_active', true)->value('id');
        if ($yearId === null) {
            return new Collection;
        }

        return SchoolClass::query()
            ->with(['subject', 'classGroup.gradeLevel', 'classGroup.academicYear', 'teacher.profile'])
            ->whereHas('classGroup', fn ($query) => $query->where('academic_year_id', $yearId))
            ->orderBy('id')
            ->get();
    }

    /**
     * Assigns or clears teacher_id only on classes of the active year.
     *
     * @param  array<int, int>  $classIds
     */
    public function sync(Teacher $teacher, array $classIds): Teacher
    {
        $yearId = AcademicYear::query()->where('is_active', true)->value('id');
        if ($yearId === null) {
            throw new RuntimeException('No hay un ciclo escolar activo.');
        }

        $yearClassIds = SchoolClass::query()
            ->whereHas('classGroup', fn ($query) => $query->where('academic_year_id', $yearId))
            ->pluck('id');

        $requested = collect($classIds)
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values();

        $invalid = $requested->diff($yearClassIds);
        if ($invalid->isNotEmpty()) {
            throw new RuntimeException('Hay clases que no pertenecen al ciclo activo.');
        }

        SchoolClass::query()
            ->where('teacher_id', $teacher->id)
            ->whereIn('id', $yearClassIds)
            ->whereNotIn('id', $requested)
            ->update(['teacher_id' => null]);

        if ($requested->isNotEmpty()) {
            SchoolClass::query()
                ->whereIn('id', $requested)
                ->update(['teacher_id' => $teacher->id]);
        }

        return $teacher->fresh([
            'profile.address',
            'schoolClasses.subject',
            'schoolClasses.classGroup.gradeLevel',
        ]);
    }
}
