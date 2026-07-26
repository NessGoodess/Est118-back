<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Enums\GeneralAttendanceStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\GeneralAttendance;
use App\Models\StudentCredentialTracking;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class DailyGeneralAttendanceService
{
    public function __construct(
        private readonly AttendanceRulesService $rules,
        private readonly StudentPhotoPathService $photoPathService
    ) {}

    /**
     * Full daily roster (students + photos + statuses). Prefer caching roster on the client
     * and calling statusesForDate() when only the date changes.
     *
     * @return array<string, mixed>
     */
    public function forDate(string $date): array
    {
        $day = Carbon::parse($date, $this->rules->timezone())->startOfDay();
        $dateString = $day->toDateString();
        $resolved = $this->resolveAcademicYear($dateString);
        $academicYear = $resolved['year'];
        $academicYearId = $academicYear->id;

        $enrollments = $this->activeEnrollments($academicYearId);
        $attendances = $this->attendancesForDate($dateString, $enrollments->pluck('student_id'));
        $trackings = $this->trackingsForYear($academicYearId, $enrollments->pluck('student_id'));
        $now = $this->rules->now();

        $students = $enrollments->map(function (Enrollment $enrollment) use (
            $attendances,
            $trackings,
            $day,
            $now
        ) {
            $student = $enrollment->student;
            $attendance = $attendances->get($student->id);
            $tracking = $trackings->get($student->id);
            $effective = $this->resolveEffectiveStatus($attendance, $day, $now);

            return [
                'student_id' => $student->id,
                'credential_id' => $student->credential_id,
                'name' => trim(
                    ($student->profile?->first_name ?? '').' '.($student->profile?->last_name ?? '')
                ),
                'photo_url' => $this->photoPathService->signedUrl($student, 'profile'),
                'gender' => $student->profile?->gender,
                'grade' => $enrollment->classGroup?->gradeLevel?->name,
                'group' => $enrollment->classGroup?->name,
                'status' => $effective->value,
                'persisted_status' => $attendance?->status,
                'entry_at' => $attendance?->entry_at?->toIso8601String(),
                'exit_at' => $attendance?->exit_at?->toIso8601String(),
                'scanned_at' => $attendance?->scanned_at?->toIso8601String(),
                'source' => $attendance?->source?->value,
                'absence_request_id' => $attendance?->absence_request_id,
                'credential_status' => $this->resolveCredentialStatus($student->credential_id, $tracking),
                'credential_tracking' => [
                    'credential_printed' => (bool) ($tracking?->credential_printed ?? false),
                    'nfc_ready' => (bool) ($tracking?->nfc_ready ?? false),
                    'ready_to_deliver' => (bool) ($tracking?->ready_to_deliver ?? false),
                    'paid' => (bool) ($tracking?->paid ?? false),
                    'delivered' => (bool) ($tracking?->delivered ?? false),
                    'lost' => (bool) ($tracking?->lost ?? false),
                    'replacement_count' => (int) ($tracking?->replacement_count ?? 0),
                    'has_nfc_uid' => filled($student->credential_id),
                ],
            ];
        });

        return [
            'date' => $dateString,
            'academic_year' => $this->academicYearPayload($academicYear, $resolved),
            'active_academic_year' => $this->activeAcademicYearPayload(),
            'rules' => $this->rules->toArray(),
            'summary' => $this->summarize($students),
            'students' => $students->values()->all(),
        ];
    }

    /**
     * Lightweight payload for date switches: no photos, no names.
     *
     * @return array<string, mixed>
     */
    public function statusesForDate(string $date): array
    {
        $day = Carbon::parse($date, $this->rules->timezone())->startOfDay();
        $dateString = $day->toDateString();
        $resolved = $this->resolveAcademicYear($dateString);
        $academicYear = $resolved['year'];
        $academicYearId = $academicYear->id;

        $studentIds = Enrollment::query()
            ->where('academic_year_id', $academicYearId)
            ->where('status', EnrollmentStatus::Active)
            ->orderBy('student_id')
            ->pluck('student_id');

        $attendances = $this->attendancesForDate($dateString, $studentIds);
        $now = $this->rules->now();

        $rows = $studentIds->map(function (int $studentId) use ($attendances, $day, $now) {
            $attendance = $attendances->get($studentId);
            $effective = $this->resolveEffectiveStatus($attendance, $day, $now);

            return [
                'student_id' => $studentId,
                'status' => $effective->value,
                'persisted_status' => $attendance?->status,
                'entry_at' => $attendance?->entry_at?->toIso8601String(),
                'exit_at' => $attendance?->exit_at?->toIso8601String(),
                'scanned_at' => $attendance?->scanned_at?->toIso8601String(),
                'source' => $attendance?->source?->value,
            ];
        });

        return [
            'date' => $dateString,
            'academic_year' => $this->academicYearPayload($academicYear, $resolved),
            'active_academic_year' => $this->activeAcademicYearPayload(),
            'rules' => $this->rules->toArray(),
            'summary' => $this->summarize($rows),
            'statuses' => $rows->values()->all(),
        ];
    }

    /**
     * @return array{year: AcademicYear, resolved_by: string, date_cycle_id: int|null, active_cycle_id: int|null}
     */
    private function resolveAcademicYear(string $dateString): array
    {
        $dateCycleId = AcademicYear::getAcademicYearId($dateString);
        $activeCycleId = AcademicYear::query()->where('is_active', true)->value('id');
        $academicYearId = $dateCycleId;
        $resolvedBy = 'date';

        if ($academicYearId) {
            $hasRoster = Enrollment::query()
                ->where('academic_year_id', $academicYearId)
                ->where('status', EnrollmentStatus::Active)
                ->exists();

            if (! $hasRoster && $activeCycleId) {
                $academicYearId = $activeCycleId;
                $resolvedBy = 'active_fallback';
            }
        } elseif ($activeCycleId) {
            $academicYearId = $activeCycleId;
            $resolvedBy = 'active';
        }

        if (! $academicYearId) {
            throw ValidationException::withMessages([
                'date' => ['No existe un ciclo escolar para la fecha seleccionada.'],
            ]);
        }

        $year = AcademicYear::query()->find($academicYearId);
        if (! $year) {
            throw ValidationException::withMessages([
                'date' => ['No existe un ciclo escolar para la fecha seleccionada.'],
            ]);
        }

        return [
            'year' => $year,
            'resolved_by' => $resolvedBy,
            'date_cycle_id' => $dateCycleId ? (int) $dateCycleId : null,
            'active_cycle_id' => $activeCycleId ? (int) $activeCycleId : null,
        ];
    }

    /**
     * @return Collection<int, Enrollment>
     */
    private function activeEnrollments(int $academicYearId): Collection
    {
        return Enrollment::query()
            ->where('academic_year_id', $academicYearId)
            ->where('status', EnrollmentStatus::Active)
            ->with([
                'student:id,credential_id,profile_id',
                'student.profile:id,first_name,last_name,profile_picture,gender,updated_at',
                'classGroup:id,name,grade_level_id',
                'classGroup.gradeLevel:id,name',
            ])
            ->get()
            ->sortBy(fn (Enrollment $e) => mb_strtolower(trim(
                ($e->student?->profile?->last_name ?? '').' '.($e->student?->profile?->first_name ?? '')
            )))
            ->values();
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $studentIds
     * @return Collection<int, GeneralAttendance>
     */
    private function attendancesForDate(string $dateString, $studentIds): Collection
    {
        return GeneralAttendance::query()
            ->where('date', $dateString)
            ->whereIn('student_id', $studentIds)
            ->get()
            ->keyBy('student_id');
    }

    /**
     * @param  Collection<int, int>|array<int, int>  $studentIds
     * @return Collection<int, StudentCredentialTracking>
     */
    private function trackingsForYear(int $academicYearId, $studentIds): Collection
    {
        return StudentCredentialTracking::query()
            ->where('academic_year_id', $academicYearId)
            ->whereIn('student_id', $studentIds)
            ->get()
            ->keyBy('student_id');
    }

    /**
     * @param  array{resolved_by: string, date_cycle_id: int|null, active_cycle_id: int|null}  $resolved
     * @return array<string, mixed>
     */
    private function academicYearPayload(AcademicYear $year, array $resolved): array
    {
        return [
            'id' => $year->id,
            'description' => $year->description,
            'year_start' => $year->year_start,
            'year_end' => $year->year_end,
            'is_active' => (bool) $year->is_active,
            'resolved_by' => $resolved['resolved_by'],
            'date_cycle_id' => $resolved['date_cycle_id'],
            'active_cycle_id' => $resolved['active_cycle_id'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function activeAcademicYearPayload(): ?array
    {
        $active = AcademicYear::query()->where('is_active', true)->first();
        if (! $active) {
            return null;
        }

        return [
            'id' => $active->id,
            'description' => $active->description,
            'year_start' => $active->year_start,
            'year_end' => $active->year_end,
            'is_active' => true,
        ];
    }

    private function resolveEffectiveStatus(
        ?GeneralAttendance $attendance,
        Carbon $day,
        Carbon $now
    ): GeneralAttendanceStatus {
        if ($attendance) {
            return match ((string) $attendance->status) {
                GeneralAttendanceStatus::Late->value => GeneralAttendanceStatus::Late,
                GeneralAttendanceStatus::Excused->value => GeneralAttendanceStatus::Excused,
                GeneralAttendanceStatus::Absent->value => GeneralAttendanceStatus::Absent,
                default => GeneralAttendanceStatus::Present,
            };
        }

        if ($day->isFuture()) {
            return GeneralAttendanceStatus::Pending;
        }

        if ($day->isToday() && ! $this->rules->isAfterEntryWindowClose($now)) {
            return GeneralAttendanceStatus::Pending;
        }

        return GeneralAttendanceStatus::Absent;
    }

    private function resolveCredentialStatus(?string $credentialId, ?StudentCredentialTracking $tracking): string
    {
        if ($tracking?->lost) {
            return 'lost';
        }

        if (($tracking?->replacement_count ?? 0) > 0 && ! filled($credentialId)) {
            return 'replacement_pending';
        }

        if (! filled($credentialId)) {
            return 'not_configured';
        }

        if ($tracking?->delivered) {
            return 'delivered';
        }

        if ($tracking?->credential_printed) {
            return 'printed';
        }

        if ($tracking?->nfc_ready) {
            return 'nfc_ready';
        }

        return 'configured';
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $students
     * @return array{total: int, present: int, late: int, absent: int, excused: int, pending: int}
     */
    private function summarize(Collection $students): array
    {
        $counts = [
            'total' => $students->count(),
            'present' => 0,
            'late' => 0,
            'absent' => 0,
            'excused' => 0,
            'pending' => 0,
        ];

        foreach ($students as $row) {
            $status = $row['status'] ?? 'pending';
            if (array_key_exists($status, $counts)) {
                $counts[$status]++;
            }
        }

        return $counts;
    }
}
