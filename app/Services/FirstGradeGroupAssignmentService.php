<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\PreEnrollment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FirstGradeGroupAssignmentService
{
    /**
     * @param  array<int, array{enrollment_id:int, class_group_id:int}>  $overrides
     * @return array<string, mixed>
     */
    public function run(
        int $academicYearId,
        string $scoreSource,
        bool $dryRun = true,
        array $overrides = []
    ): array {
        if (!in_array($scoreSource, ['school_average', 'admission_exam'], true)) {
            throw new RuntimeException('Fuente de puntaje inválida.');
        }

        $firstGrade = GradeLevel::query()->where('name', '1°')->first();
        if (! $firstGrade) {
            throw new RuntimeException('No existe el grado 1° en el catálogo.');
        }

        $groups = ClassGroup::query()
            ->where('academic_year_id', $academicYearId)
            ->where('grade_level_id', $firstGrade->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        if ($groups->isEmpty()) {
            throw new RuntimeException('No hay grupos de 1° creados para el ciclo seleccionado.');
        }

        $groupIds = $groups->pluck('id')->all();
        $groupNamesById = $groups->pluck('name', 'id')->all();

        // Carga base para balanceo (activos de 1° de ese ciclo).
        $baseCounts = Enrollment::query()
            ->where('academic_year_id', $academicYearId)
            ->where('status', EnrollmentStatus::Active->value)
            ->whereIn('class_group_id', $groupIds)
            ->selectRaw('class_group_id, COUNT(*) as total')
            ->groupBy('class_group_id')
            ->pluck('total', 'class_group_id')
            ->map(fn ($n) => (int) $n)
            ->all();

        $counts = [];
        foreach ($groupIds as $gid) {
            $counts[$gid] = $baseCounts[$gid] ?? 0;
        }

        $candidates = Enrollment::query()
            ->with(['student.profile:id,first_name,last_name'])
            ->where('academic_year_id', $academicYearId)
            ->where('status', EnrollmentStatus::Active->value)
            ->where('is_new_admission', true)
            ->whereIn('class_group_id', $groupIds)
            ->get();

        $studentIds = $candidates->pluck('student_id')->all();
        $preByStudentId = PreEnrollment::query()
            ->whereIn('converted_student_id', $studentIds)
            ->get(['id', 'converted_student_id', 'previous_school', 'current_average', 'admission_exam_score'])
            ->keyBy('converted_student_id');

        $overrideMap = [];
        foreach ($overrides as $override) {
            $overrideMap[(int) $override['enrollment_id']] = (int) $override['class_group_id'];
        }

        $rows = $candidates->map(function (Enrollment $enrollment) use ($preByStudentId, $scoreSource) {
            $pre = $preByStudentId->get($enrollment->student_id);
            $schoolAvg = $pre?->current_average !== null ? (float) $pre->current_average : null;
            $examScore = $pre?->admission_exam_score !== null ? (float) $pre->admission_exam_score : null;

            $resolved = $scoreSource === 'admission_exam' ? $examScore : $schoolAvg;
            $fallbackUsed = false;

            if ($resolved === null) {
                $resolved = $schoolAvg ?? $examScore ?? 0.0;
                $fallbackUsed = true;
            }

            $rawLastName = (string) ($enrollment->student?->profile?->last_name ?? '');
            $surnameToken = strtoupper(trim((string) explode(' ', $rawLastName)[0]));

            return [
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'student_name' => trim(
                    ($enrollment->student?->profile?->first_name ?? '') . ' ' .
                    ($enrollment->student?->profile?->last_name ?? '')
                ),
                'surname_token' => $surnameToken,
                'previous_school' => $pre?->previous_school,
                'school_average' => $schoolAvg,
                'admission_exam_score' => $examScore,
                'score_used' => round((float) $resolved, 2),
                'fallback_used' => $fallbackUsed,
            ];
        })->sortByDesc('score_used')->values();

        $assignments = [];
        foreach ($rows as $row) {
            $enrollmentId = (int) $row['enrollment_id'];

            if (isset($overrideMap[$enrollmentId])) {
                $target = $overrideMap[$enrollmentId];
                if (!in_array($target, $groupIds, true)) {
                    throw new RuntimeException("El grupo override {$target} no pertenece a 1° del ciclo.");
                }
            } else {
                // Menor carga total, desempate por letra.
                $target = collect($counts)
                    ->sortBy(fn ($total, $gid) => sprintf('%05d-%s', $total, $groupNamesById[$gid] ?? 'Z'))
                    ->keys()
                    ->first();
            }

            $counts[$target] = ($counts[$target] ?? 0) + 1;

            $assignments[] = [
                ...$row,
                'current_group_id' => $this->findCurrentGroupId($candidates, $enrollmentId),
                'suggested_group_id' => $target,
                'suggested_group_name' => $groupNamesById[$target] ?? null,
                'flags' => [],
                'manual_override' => array_key_exists($enrollmentId, $overrideMap),
            ];
        }

        $assignments = $this->markConflictFlags($assignments);

        if (! $dryRun) {
            DB::transaction(function () use ($assignments) {
                foreach ($assignments as $assignment) {
                    Enrollment::query()
                        ->whereKey($assignment['enrollment_id'])
                        ->update(['class_group_id' => $assignment['suggested_group_id']]);
                }
            });
        }

        return [
            'score_source' => $scoreSource,
            'dry_run' => $dryRun,
            'group_loads' => collect($counts)->map(function ($total, $gid) use ($groupNamesById) {
                return [
                    'class_group_id' => (int) $gid,
                    'group_name' => $groupNamesById[$gid] ?? null,
                    'total' => (int) $total,
                ];
            })->values()->all(),
            'assignments' => $assignments,
            'summary' => [
                'total_candidates' => count($assignments),
                'with_conflicts' => count(array_filter($assignments, fn ($a) => count($a['flags']) > 0)),
                'fallback_scores' => count(array_filter($assignments, fn ($a) => $a['fallback_used'])),
            ],
        ];
    }

    /**
     * @param  array<int, array<string,mixed>>  $assignments
     * @return array<int, array<string,mixed>>
     */
    private function markConflictFlags(array $assignments): array
    {
        $schoolBuckets = [];
        $surnameBuckets = [];

        foreach ($assignments as $idx => $a) {
            $groupId = (int) $a['suggested_group_id'];
            $school = strtoupper(trim((string) ($a['previous_school'] ?? '')));
            $surname = strtoupper(trim((string) ($a['surname_token'] ?? '')));

            if ($school !== '') {
                $schoolBuckets[$groupId][$school][] = $idx;
            }

            if ($surname !== '') {
                $surnameBuckets[$groupId][$surname][] = $idx;
            }
        }

        foreach ($schoolBuckets as $bySchool) {
            foreach ($bySchool as $indices) {
                if (count($indices) <= 1) {
                    continue;
                }
                foreach ($indices as $i) {
                    $assignments[$i]['flags'][] = 'same_previous_school';
                }
            }
        }

        foreach ($surnameBuckets as $bySurname) {
            foreach ($bySurname as $indices) {
                if (count($indices) <= 1) {
                    continue;
                }
                foreach ($indices as $i) {
                    $assignments[$i]['flags'][] = 'possible_siblings_by_lastname';
                }
            }
        }

        foreach ($assignments as &$a) {
            $a['flags'] = array_values(array_unique($a['flags']));
        }

        return $assignments;
    }

    private function findCurrentGroupId($candidates, int $enrollmentId): ?int
    {
        $item = $candidates->firstWhere('id', $enrollmentId);
        return $item ? (int) $item->class_group_id : null;
    }
}

