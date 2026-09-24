<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\PreEnrollment;
use App\Models\Workshop;
use App\Models\WorkshopEnrollment;
use App\Models\WorkshopOffering;
use App\Support\WorkshopLetterHint;
use App\Enums\AdmissionWorkshop;
use App\Models\AdmissionIntakeSetting;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class FirstGradeWorkshopAssignmentService
{
    public function __construct(
        private readonly WorkshopEnrollmentWriter $writer
    ) {}

    /**
     * @param  array<int, array{enrollment_id:int, workshop_id:int}>  $overrides
     * @return array<string, mixed>
     */
    public function run(
        int $academicYearId,
        ?string $scoreSource = null,
        bool $dryRun = true,
        array $overrides = [],
        bool $warnGhMismatch = true,
    ): array {
        $settings = AdmissionIntakeSetting::current();
        $resolvedSource = $this->resolveScoreSource($scoreSource, $settings);

        $firstGrade = GradeLevel::query()->where('name', '1°')->first();
        if (! $firstGrade) {
            throw new RuntimeException('No existe el grado 1° en el catálogo.');
        }

        $offerings = WorkshopOffering::query()
            ->with('workshop')
            ->where('academic_year_id', $academicYearId)
            ->where('is_open_for_intake', true)
            ->get();

        if ($offerings->isEmpty()) {
            throw new RuntimeException('No hay oferta de talleres para el ciclo seleccionado.');
        }

        foreach ($offerings as $offering) {
            if ($offering->capacity === null) {
                throw new RuntimeException(
                    "El taller {$offering->workshop?->name} no tiene cupo definido para este ciclo."
                );
            }
        }

        $workshopsById = Workshop::query()->where('is_active', true)->get()->keyBy('id');
        $workshopsByName = [];
        $internalLast = null;
        foreach ($workshopsById as $workshop) {
            if ($workshop->code === Workshop::OFIMATICA_CODE && $workshop->is_internal) {
                $internalLast = $workshop;

                continue;
            }
            $workshopsByName[AdmissionWorkshop::normalize($workshop->name)] = $workshop;
        }

        $enrollments = Enrollment::query()
            ->with(['student.profile', 'classGroup'])
            ->where('academic_year_id', $academicYearId)
            ->where('status', EnrollmentStatus::Active->value)
            ->whereHas('classGroup', fn ($q) => $q->where('grade_level_id', $firstGrade->id))
            ->get();

        $existingByStudent = WorkshopEnrollment::query()
            ->where('academic_year_id', $academicYearId)
            ->get()
            ->keyBy('student_id');

        $candidates = $enrollments->filter(function (Enrollment $enrollment) use ($existingByStudent) {
            $existing = $existingByStudent->get($enrollment->student_id);
            if (! $existing) {
                return true;
            }

            return ! $existing->isProtectedFromBatch();
        })->values();

        $occupied = [];
        foreach ($offerings as $offering) {
            $occupied[$offering->workshop_id] = WorkshopEnrollment::query()
                ->where('academic_year_id', $academicYearId)
                ->where('workshop_id', $offering->workshop_id)
                ->where('status', WorkshopEnrollmentStatus::Assigned->value)
                ->whereNotIn('student_id', $candidates->pluck('student_id'))
                ->count();
        }

        $preByStudentId = PreEnrollment::query()
            ->whereIn('converted_student_id', $candidates->pluck('student_id'))
            ->get()
            ->keyBy('converted_student_id');

        $overrideMap = [];
        foreach ($overrides as $override) {
            $overrideMap[(int) $override['enrollment_id']] = (int) $override['workshop_id'];
        }

        $rows = $candidates->map(function (Enrollment $enrollment) use ($preByStudentId, $resolvedSource, $settings) {
            $pre = $preByStudentId->get($enrollment->student_id);
            $schoolAvg = $pre?->current_average !== null ? (float) $pre->current_average : null;
            $examScore = $pre?->admission_exam_score !== null ? (float) $pre->admission_exam_score : null;
            [$score, $fallbackUsed] = $this->resolveScore($resolvedSource, $schoolAvg, $examScore, $settings);
            $missingScore = $schoolAvg === null && $examScore === null;

            return [
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'student_name' => trim(
                    ($enrollment->student?->profile?->first_name ?? '').' '.
                    ($enrollment->student?->profile?->last_name ?? '')
                ),
                'class_group_name' => $enrollment->classGroup?->name,
                'first_choice' => $pre?->workshop_first_choice,
                'second_choice' => $pre?->workshop_second_choice,
                'score_used' => $score,
                'fallback_used' => $fallbackUsed,
                'blocked_missing_score' => $settings->require_score_before_placement && $missingScore,
                'folio' => $pre?->folio,
            ];
        })->sort(function (array $a, array $b) {
            $scoreCmp = $b['score_used'] <=> $a['score_used'];
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            return strcmp((string) $a['folio'], (string) $b['folio']);
        })->values();

        $assignments = [];
        foreach ($rows as $row) {
            $flags = [];
            $willApply = true;
            $workshopId = $overrideMap[$row['enrollment_id']] ?? null;
            $source = WorkshopEnrollmentSource::Manual;
            $status = WorkshopEnrollmentStatus::Assigned;

            if ($row['blocked_missing_score'] && $workshopId === null) {
                $flags[] = 'missing_score';
                $willApply = false;
                $source = WorkshopEnrollmentSource::Leftover;
                $status = WorkshopEnrollmentStatus::Waitlisted;
            } elseif ($workshopId !== null) {
                $source = WorkshopEnrollmentSource::Manual;
                if (! $this->hasSeat($occupied, $offerings, $workshopId)) {
                    $flags[] = 'over_capacity';
                }
                $occupied[$workshopId] = ($occupied[$workshopId] ?? 0) + 1;
            } else {
                $first = $this->resolveWorkshop($row['first_choice'] ?? '', $workshopsByName);
                $second = $this->resolveWorkshop($row['second_choice'] ?? '', $workshopsByName);

                if ($first && $this->hasSeat($occupied, $offerings, $first->id)) {
                    $workshopId = $first->id;
                    $source = WorkshopEnrollmentSource::FirstChoice;
                    $occupied[$first->id] = ($occupied[$first->id] ?? 0) + 1;
                } elseif ($second && $this->hasSeat($occupied, $offerings, $second->id)) {
                    $workshopId = $second->id;
                    $source = WorkshopEnrollmentSource::SecondChoice;
                    $occupied[$second->id] = ($occupied[$second->id] ?? 0) + 1;
                    $flags[] = 'second_choice_used';
                } elseif ($internalLast) {
                    $workshopId = $internalLast->id;
                    $source = WorkshopEnrollmentSource::Leftover;
                    $status = WorkshopEnrollmentStatus::Assigned;
                    $flags[] = 'internal_last_option';
                } else {
                    $workshopId = $first?->id ?? $second?->id;
                    $source = WorkshopEnrollmentSource::Leftover;
                    $status = WorkshopEnrollmentStatus::Waitlisted;
                    $flags[] = 'waitlisted';
                    $willApply = $workshopId !== null;
                }
            }

            if ($warnGhMismatch && $workshopId && $row['class_group_name']) {
                $expected = WorkshopLetterHint::expectedCode((string) $row['class_group_name']);
                $code = $workshopsById->get($workshopId)?->code;
                if ($expected && $code && $expected !== $code) {
                    $flags[] = 'gh_mismatch';
                }
            }

            $assignments[] = [
                ...$row,
                'workshop_id' => $workshopId,
                'workshop_name' => $workshopId ? ($workshopsById->get($workshopId)?->name) : null,
                'source' => $source->value,
                'status' => $status->value,
                'flags' => $flags,
                'will_apply' => $willApply && $workshopId !== null,
            ];
        }

        if (! $dryRun) {
            DB::transaction(function () use ($assignments, $academicYearId) {
                foreach ($assignments as $assignment) {
                    if (! ($assignment['will_apply'] ?? false)) {
                        continue;
                    }

                    $this->writer->upsert(
                        studentId: $assignment['student_id'],
                        academicYearId: $academicYearId,
                        workshopId: (int) $assignment['workshop_id'],
                        source: WorkshopEnrollmentSource::from($assignment['source']),
                        status: WorkshopEnrollmentStatus::from($assignment['status']),
                        notes: null,
                        assignedBy: null,
                        protectManual: true,
                    );
                }
            });
        }

        $loads = $offerings->map(function (WorkshopOffering $offering) use ($occupied) {
            return [
                'workshop_id' => $offering->workshop_id,
                'workshop_name' => $offering->workshop?->name,
                'capacity' => $offering->capacity,
                'occupied' => (int) ($occupied[$offering->workshop_id] ?? 0),
                'available' => max(0, (int) $offering->capacity - (int) ($occupied[$offering->workshop_id] ?? 0)),
            ];
        })->values()->all();

        return [
            'score_source' => $resolvedSource,
            'dry_run' => $dryRun,
            'workshop_loads' => $loads,
            'assignments' => $assignments,
            'summary' => [
                'total_candidates' => count($assignments),
                'assigned' => count(array_filter($assignments, fn ($a) => ($a['status'] ?? '') === 'assigned' && ($a['will_apply'] ?? false))),
                'waitlisted' => count(array_filter($assignments, fn ($a) => in_array('waitlisted', $a['flags'], true))),
                'second_choice' => count(array_filter($assignments, fn ($a) => ($a['source'] ?? '') === 'second_choice')),
                'gh_mismatch' => count(array_filter($assignments, fn ($a) => in_array('gh_mismatch', $a['flags'], true))),
            ],
            'academic_year_id' => $academicYearId,
        ];
    }

    /**
     * @param  array<string, \App\Models\Workshop>  $byName
     */
    private function resolveWorkshop(string $name, array $byName): ?Workshop
    {
        $key = AdmissionWorkshop::normalize($name);

        return $byName[$key] ?? null;
    }

    /**
     * @param  array<int, int>  $occupied
     * @param  \Illuminate\Support\Collection<int, WorkshopOffering>  $offerings
     */
    private function hasSeat(array $occupied, $offerings, int $workshopId): bool
    {
        $offering = $offerings->firstWhere('workshop_id', $workshopId);
        if (! $offering || $offering->capacity === null) {
            return false;
        }

        return ((int) ($occupied[$workshopId] ?? 0)) < (int) $offering->capacity;
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
}
