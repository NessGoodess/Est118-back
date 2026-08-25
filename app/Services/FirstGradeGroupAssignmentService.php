<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Models\AdmissionIntakeSetting;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\PreEnrollment;
use Illuminate\Support\Collection;
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
        ?string $scoreSource = null,
        bool $dryRun = true,
        array $overrides = [],
    ): array {
        $settings = AdmissionIntakeSetting::current();
        $resolvedSource = $this->resolveScoreSource($scoreSource, $settings);

        if ($overrides !== [] && ! $settings->allow_manual_group_change) {
            throw new RuntimeException('Los cambios manuales de grupo no están permitidos por la configuración de ingreso.');
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

        $enrollmentsInGroups = Enrollment::query()
            ->with(['student.profile:id,first_name,last_name'])
            ->where('academic_year_id', $academicYearId)
            ->where('status', EnrollmentStatus::Active->value)
            ->whereIn('class_group_id', $groupIds)
            ->get();

        $candidates = $enrollmentsInGroups
            ->filter(fn (Enrollment $enrollment) => $this->isAssignableCandidate($enrollment, $settings))
            ->values();

        $candidateIds = $candidates->pluck('id')->all();
        $lockedEnrollments = $enrollmentsInGroups
            ->reject(fn (Enrollment $enrollment) => in_array($enrollment->id, $candidateIds, true))
            ->values();

        // Base load = students that will not move in this run (avoids double-counting candidates).
        $counts = [];
        $scoreSums = [];
        foreach ($groupIds as $gid) {
            $counts[$gid] = 0;
            $scoreSums[$gid] = 0.0;
        }
        foreach ($lockedEnrollments as $locked) {
            $gid = (int) $locked->class_group_id;
            if (! isset($counts[$gid])) {
                continue;
            }
            $counts[$gid]++;
        }

        $studentIds = $candidates->pluck('student_id')
            ->merge($lockedEnrollments->pluck('student_id'))
            ->unique()
            ->values()
            ->all();

        $preByStudentId = PreEnrollment::query()
            ->whereIn('converted_student_id', $studentIds)
            ->get([
                'id',
                'converted_student_id',
                'previous_school',
                'current_average',
                'admission_exam_score',
                'has_siblings',
                'guardian_curp',
            ])
            ->keyBy('converted_student_id');

        /** @var array<int, list<string>> $schoolsInGroup */
        $schoolsInGroup = [];
        /** @var array<int, list<string>> $surnamesInGroup */
        $surnamesInGroup = [];
        /** @var array<int, list<string>> $guardiansInGroup */
        $guardiansInGroup = [];
        foreach ($groupIds as $gid) {
            $schoolsInGroup[$gid] = [];
            $surnamesInGroup[$gid] = [];
            $guardiansInGroup[$gid] = [];
        }

        foreach ($lockedEnrollments as $locked) {
            $gid = (int) $locked->class_group_id;
            $pre = $preByStudentId->get($locked->student_id);
            $rawLastName = (string) ($locked->student?->profile?->last_name ?? '');
            $surnameToken = strtoupper(trim((string) explode(' ', $rawLastName)[0]));
            $schoolNorm = $this->normalizeSchool((string) ($pre?->previous_school ?? ''));
            $guardianCurp = strtoupper(trim((string) ($pre?->guardian_curp ?? '')));

            if ($schoolNorm !== '') {
                $schoolsInGroup[$gid][] = $schoolNorm;
            }
            if ($surnameToken !== '') {
                $surnamesInGroup[$gid][] = $surnameToken;
            }
            if ($guardianCurp !== '') {
                $guardiansInGroup[$gid][] = $guardianCurp;
            }
        }

        $overrideMap = [];
        foreach ($overrides as $override) {
            $overrideMap[(int) $override['enrollment_id']] = (int) $override['class_group_id'];
        }

        $rows = $candidates->map(function (Enrollment $enrollment) use ($preByStudentId, $resolvedSource, $settings) {
            $pre = $preByStudentId->get($enrollment->student_id);
            $schoolAvg = $pre?->current_average !== null ? (float) $pre->current_average : null;
            $examScore = $pre?->admission_exam_score !== null ? (float) $pre->admission_exam_score : null;

            [$resolved, $fallbackUsed] = $this->resolveScore($resolvedSource, $schoolAvg, $examScore, $settings);
            $missingScore = $schoolAvg === null && $examScore === null;
            $blockedMissingScore = $settings->require_score_before_placement && $missingScore;

            $rawLastName = (string) ($enrollment->student?->profile?->last_name ?? '');
            $surnameToken = strtoupper(trim((string) explode(' ', $rawLastName)[0]));
            $schoolNorm = $this->normalizeSchool((string) ($pre?->previous_school ?? ''));

            return [
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'student_name' => trim(
                    ($enrollment->student?->profile?->first_name ?? '').' '.
                    ($enrollment->student?->profile?->last_name ?? '')
                ),
                'surname_token' => $surnameToken,
                'previous_school' => $pre?->previous_school,
                'previous_school_norm' => $schoolNorm,
                'guardian_curp' => strtoupper(trim((string) ($pre?->guardian_curp ?? ''))),
                'has_siblings' => (bool) ($pre?->has_siblings ?? false),
                'school_average' => $schoolAvg,
                'admission_exam_score' => $examScore,
                'score_used' => round((float) $resolved, 2),
                'fallback_used' => $fallbackUsed,
                'missing_score' => $missingScore,
                'blocked_missing_score' => $blockedMissingScore,
            ];
        })->sortByDesc('score_used')->values();

        $assignments = [];
        foreach ($rows as $row) {
            $enrollmentId = (int) $row['enrollment_id'];
            $currentGroupId = $this->findCurrentGroupId($candidates, $enrollmentId);
            $flags = [];
            $manualOverride = array_key_exists($enrollmentId, $overrideMap);
            $applied = true;

            if ($row['blocked_missing_score']) {
                $target = $currentGroupId;
                $flags[] = 'missing_score';
                $applied = false;
            } elseif ($manualOverride) {
                $target = $overrideMap[$enrollmentId];
                if (! in_array($target, $groupIds, true)) {
                    throw new RuntimeException("El grupo override {$target} no pertenece a 1° del ciclo.");
                }
            } else {
                $target = $this->pickGroup(
                    $groupIds,
                    $groupNamesById,
                    $counts,
                    $scoreSums,
                    $schoolsInGroup,
                    $surnamesInGroup,
                    $guardiansInGroup,
                    $row,
                    $settings,
                );

                if ($target === null) {
                    $target = $currentGroupId;
                    $flags[] = 'hard_constraint_unresolved';
                    $applied = false;
                }
            }

            if ($applied && $target !== null) {
                $counts[$target] = ($counts[$target] ?? 0) + 1;
                $scoreSums[$target] = ($scoreSums[$target] ?? 0) + (float) $row['score_used'];

                if ($row['previous_school_norm'] !== '') {
                    $schoolsInGroup[$target][] = $row['previous_school_norm'];
                }
                if ($row['surname_token'] !== '') {
                    $surnamesInGroup[$target][] = $row['surname_token'];
                }
                if ($row['guardian_curp'] !== '') {
                    $guardiansInGroup[$target][] = $row['guardian_curp'];
                }
            }

            $assignments[] = [
                ...$row,
                'current_group_id' => $currentGroupId,
                'suggested_group_id' => $target,
                'suggested_group_name' => $target !== null ? ($groupNamesById[$target] ?? null) : null,
                'flags' => $flags,
                'manual_override' => $manualOverride,
                'will_apply' => $applied && $target !== null,
            ];
        }

        $assignments = $this->markConflictFlags($assignments, $settings);

        if (! $dryRun) {
            DB::transaction(function () use ($assignments) {
                foreach ($assignments as $assignment) {
                    if (! ($assignment['will_apply'] ?? false)) {
                        continue;
                    }

                    Enrollment::query()
                        ->whereKey($assignment['enrollment_id'])
                        ->update([
                            'class_group_id' => $assignment['suggested_group_id'],
                            'placement_status' => 'placed',
                            'placed_at' => now(),
                        ]);
                }
            });
        }

        return [
            'score_source' => $resolvedSource,
            'score_mode' => $settings->score_mode,
            'dry_run' => $dryRun,
            'settings' => [
                'separate_same_school' => $settings->separate_same_school,
                'separate_siblings' => $settings->separate_siblings,
                'sibling_detection' => $settings->sibling_detection,
                'require_score_before_placement' => $settings->require_score_before_placement,
                'allow_manual_group_change' => $settings->allow_manual_group_change,
                'late_lock_batch_rebalance' => $settings->late_lock_batch_rebalance,
            ],
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
                'skipped' => count(array_filter($assignments, fn ($a) => ! ($a['will_apply'] ?? false))),
                'locked_in_groups' => $lockedEnrollments->count(),
            ],
        ];
    }

    private function isAssignableCandidate(Enrollment $enrollment, AdmissionIntakeSetting $settings): bool
    {
        if (! $enrollment->is_new_admission) {
            return false;
        }

        // Never redistribute enrollments already confirmed as placed.
        if (($enrollment->placement_status ?? null) === 'placed') {
            return false;
        }

        if ($settings->late_lock_batch_rebalance) {
            $channel = $enrollment->admission_channel ?? 'campaign';
            if ($channel === 'late') {
                return false;
            }
        }

        return true;
    }

    private function resolveScoreSource(?string $scoreSource, AdmissionIntakeSetting $settings): string
    {
        if (in_array($scoreSource, ['school_average', 'admission_exam', 'combined'], true)) {
            return $scoreSource;
        }

        return match ($settings->score_mode) {
            'exam' => 'admission_exam',
            'combined' => 'combined',
            default => 'school_average',
        };
    }

    /**
     * @return array{0: float, 1: bool}
     */
    private function resolveScore(
        string $source,
        ?float $schoolAvg,
        ?float $examScore,
        AdmissionIntakeSetting $settings,
    ): array {
        if ($source === 'admission_exam') {
            if ($examScore !== null) {
                return [$examScore, false];
            }

            return [$schoolAvg ?? 0.0, true];
        }

        if ($source === 'school_average') {
            if ($schoolAvg !== null) {
                return [$schoolAvg, false];
            }

            return [$examScore ?? 0.0, true];
        }

        // combined
        if ($schoolAvg !== null && $examScore !== null) {
            $wExam = (float) $settings->exam_weight;
            $wAvg = (float) $settings->average_weight;
            $sum = $wExam + $wAvg;
            if ($sum <= 0) {
                $sum = 1.0;
                $wExam = 0.5;
                $wAvg = 0.5;
            }

            return [($examScore * $wExam + $schoolAvg * $wAvg) / $sum, false];
        }

        return [$schoolAvg ?? $examScore ?? 0.0, true];
    }

    /**
     * @param  list<int>  $groupIds
     * @param  array<int, string>  $groupNamesById
     * @param  array<int, int>  $counts
     * @param  array<int, float>  $scoreSums
     * @param  array<int, list<string>>  $schoolsInGroup
     * @param  array<int, list<string>>  $surnamesInGroup
     * @param  array<int, list<string>>  $guardiansInGroup
     * @param  array<string, mixed>  $row
     */
    private function pickGroup(
        array $groupIds,
        array $groupNamesById,
        array $counts,
        array $scoreSums,
        array $schoolsInGroup,
        array $surnamesInGroup,
        array $guardiansInGroup,
        array $row,
        AdmissionIntakeSetting $settings,
    ): ?int {
        $best = null;
        $bestCost = null;

        foreach ($groupIds as $gid) {
            $schoolHit = $row['previous_school_norm'] !== ''
                && in_array($row['previous_school_norm'], $schoolsInGroup[$gid] ?? [], true);
            $surnameHit = $row['surname_token'] !== ''
                && in_array($row['surname_token'], $surnamesInGroup[$gid] ?? [], true);
            $guardianHit = $row['guardian_curp'] !== ''
                && in_array($row['guardian_curp'], $guardiansInGroup[$gid] ?? [], true);

            $siblingHit = match ($settings->sibling_detection) {
                'lastname_warn' => $surnameHit,
                'guardian_curp' => $guardianHit || $surnameHit,
                default => $guardianHit,
            };

            if ($settings->separate_same_school === 'hard' && $schoolHit) {
                continue;
            }
            if ($settings->separate_siblings === 'hard' && $siblingHit) {
                continue;
            }

            $load = $counts[$gid] ?? 0;
            $avgScore = $load > 0 ? ($scoreSums[$gid] / $load) : 0.0;
            $cost = 0.0;
            if ($settings->balance_load) {
                $cost += $load * 10;
            }
            if ($settings->balance_scores) {
                $cost += abs($avgScore - (float) $row['score_used']);
            }
            if ($settings->separate_same_school === 'soft' && $schoolHit) {
                $cost += 100;
            }
            if ($settings->separate_siblings === 'soft' && $siblingHit) {
                $cost += 120;
            }
            // Stable tie-break by letter
            $cost += (ord($groupNamesById[$gid] ?? 'Z') - 65) * 0.01;

            if ($bestCost === null || $cost < $bestCost) {
                $bestCost = $cost;
                $best = $gid;
            }
        }

        // Do not fall back to least-load when every group violates a hard constraint.
        return $best !== null ? (int) $best : null;
    }

    /**
     * @param  array<int, array<string,mixed>>  $assignments
     * @return array<int, array<string,mixed>>
     */
    private function markConflictFlags(array $assignments, AdmissionIntakeSetting $settings): array
    {
        $schoolBuckets = [];
        $surnameBuckets = [];
        $guardianBuckets = [];

        foreach ($assignments as $idx => $a) {
            if (($a['suggested_group_id'] ?? null) === null) {
                continue;
            }

            $groupId = (int) $a['suggested_group_id'];
            $school = (string) ($a['previous_school_norm'] ?? '');
            $surname = strtoupper(trim((string) ($a['surname_token'] ?? '')));
            $guardian = strtoupper(trim((string) ($a['guardian_curp'] ?? '')));

            if ($school !== '' && $settings->separate_same_school !== 'off') {
                $schoolBuckets[$groupId][$school][] = $idx;
            }
            if ($surname !== '' && in_array($settings->sibling_detection, ['lastname_warn', 'guardian_curp'], true)
                && $settings->separate_siblings !== 'off') {
                $surnameBuckets[$groupId][$surname][] = $idx;
            }
            if ($guardian !== '' && $settings->sibling_detection === 'guardian_curp'
                && $settings->separate_siblings !== 'off') {
                $guardianBuckets[$groupId][$guardian][] = $idx;
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

        foreach ($guardianBuckets as $byGuardian) {
            foreach ($byGuardian as $indices) {
                if (count($indices) <= 1) {
                    continue;
                }
                foreach ($indices as $i) {
                    $assignments[$i]['flags'][] = 'same_guardian_curp';
                }
            }
        }

        foreach ($assignments as &$a) {
            $a['flags'] = array_values(array_unique($a['flags']));
            unset(
                $a['previous_school_norm'],
                $a['guardian_curp'],
                $a['has_siblings'],
                $a['missing_score'],
                $a['blocked_missing_score'],
            );
        }

        return $assignments;
    }

    private function normalizeSchool(string $value): string
    {
        $v = strtoupper(trim($value));
        $v = strtr($v, [
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U', 'Ñ' => 'N',
            'Ü' => 'U',
        ]);
        $v = preg_replace('/\b(ESCUELA|PRIMARIA|SECUNDARIA|COLEGIO|INSTITUTO|TECNICA|TÉCNICA)\b/u', '', $v) ?? $v;
        $v = preg_replace('/[^A-Z0-9]+/', ' ', $v) ?? $v;

        return trim(preg_replace('/\s+/', ' ', $v) ?? $v);
    }

    /**
     * @param  Collection<int, Enrollment>  $candidates
     */
    private function findCurrentGroupId(Collection $candidates, int $enrollmentId): ?int
    {
        $item = $candidates->firstWhere('id', $enrollmentId);

        return $item ? (int) $item->class_group_id : null;
    }
}
