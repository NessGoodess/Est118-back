<?php

namespace App\Services;

use App\Enums\AttendanceSource;
use App\Enums\EnrollmentStatus;
use App\Enums\GeneralAttendanceStatus;
use App\Jobs\SendTelegramNotificationJob;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\GeneralAttendance;
use App\Models\RecentReading;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Business logic for NFC card_inserted events.
 *
 * Same physical readers handle entry and exit; the active mode is decided by
 * AttendanceRulesService time windows, not by reader_direction.
 *
 * general_attendances = source of truth for attendance
 * recent_readings     = identification / event log (always written when processed)
 */
class NfcAttendanceService
{
    public function __construct(
        private readonly NfcReaderSlotService $slotService,
        private readonly StudentPhotoPathService $photoPathService,
        private readonly AttendanceRulesService $rules
    ) {}

    public function processCardInserted(array $data): array
    {
        // Attendance path never completes pairing; that happens only in the webhook controller.
        $payload = $this->slotService->enrichPayload($data, [
            'reader' => $data['reader'] ?? 'NFC Reader',
            'timestamp' => $this->rules->now()->toIso8601String(),
        ], allowPairing: false);

        if (empty($payload['reader_slot_id'])) {
            return $payload + [
                'event' => 'card_inserted',
                'status' => 'warning',
                'message' => 'Lector no emparejado. Asigna el PC/SC a un panel antes de registrar asistencia.',
                'student' => null,
                'attendance_skipped' => true,
            ];
        }

        if (array_key_exists('reader_armed', $payload) && $payload['reader_armed'] === false) {
            return $payload + [
                'event' => 'card_inserted',
                'status' => 'warning',
                'message' => 'Lector en pausa. Active las lecturas para registrar asistencia.',
                'student' => null,
                'attendance_skipped' => true,
            ];
        }

        $credentialId = $data['credential_id'] ?? null;

        if (! $credentialId || $credentialId === 'Null') {
            return $payload + [
                'event' => 'card_inserted',
                'status' => 'warning',
                'message' => 'Credencial vacía o ilegible.',
                'student' => null,
            ];
        }

        $today = $this->rules->now()->toDateString();

        // Prefer the administratively active cycle for live NFC.
        // getAcademicYearId() alone can map mid-year dates to the previous cycle.
        $academicYearId = AcademicYear::query()->where('is_active', true)->value('id')
            ?? AcademicYear::getAcademicYearId($today);

        $enrollmentQuery = Enrollment::with([
            'student',
            'student.profile',
            'classGroup.gradeLevel',
            'academicYear:id',
        ])
            ->where('status', EnrollmentStatus::Active)
            ->whereHas('student', fn ($q) => $q->where('credential_id', $credentialId));

        if ($academicYearId) {
            $enrollmentQuery->where('academic_year_id', $academicYearId);
        }

        $enrollment = $enrollmentQuery->first();

        if (! $enrollment) {
            return $payload + [
                'event' => 'card_inserted',
                'credential_id' => $credentialId,
                'status' => 'warning',
                'message' => 'Credencial no registrada.',
                'student' => null,
            ];
        }

        $student = $enrollment->student;
        $studentData = $this->buildStudentData($enrollment);
        $lockKey = "nfc-attendance:student:{$student->id}";

        return Cache::lock($lockKey, 10)->block(5, function () use (
            $credentialId,
            $enrollment,
            $payload,
            $student,
            $studentData,
            $lockKey
        ): array {
            // Soft debounce: still show identity for prefecture, skip another DB write
            // if the same card is held against the reader for a few seconds.
            if (Cache::has("{$lockKey}:recent")) {
                return $payload + [
                    'event' => 'card_inserted',
                    'credential_id' => $credentialId,
                    'status' => 'info',
                    'student' => $studentData,
                    'message' => 'Identificado.',
                ];
            }

            $result = $this->processAttendance(
                $enrollment,
                $student->id,
                $credentialId,
                $payload,
                $studentData
            );

            Cache::put("{$lockKey}:recent", true, now()->addSeconds(4));

            return $result;
        });
    }

    /**
     * Time-window mode (same readers all day):
     * - No row today            → create entry
     * - Entry, before exit time → identify only ("Entrada ya registrada")
     * - Entry, at/after exit    → register exit once
     * - Entry + exit done       → identify only ("Asistencia completa")
     */
    private function processAttendance(
        Enrollment $enrollment,
        int $studentId,
        string $credentialId,
        array $payload,
        array $studentData
    ): array {
        $currentTime = $this->rules->now();
        $today = $currentTime->toDateString();

        $todayAttendance = GeneralAttendance::query()
            ->where('student_id', $studentId)
            ->where('date', $today)
            ->first();

        if (! $todayAttendance) {
            $status = $this->rules->isLateEntry($currentTime)
                ? GeneralAttendanceStatus::Late->value
                : GeneralAttendanceStatus::Present->value;

            $message = $status === GeneralAttendanceStatus::Late->value
                ? 'Entrada tardía registrada.'
                : 'Entrada registrada.';

            DB::transaction(function () use (
                $enrollment,
                $studentId,
                $credentialId,
                $currentTime,
                $today,
                $status,
                $message
            ): void {
                GeneralAttendance::create([
                    'student_id' => $studentId,
                    'academic_year_id' => $enrollment->academicYear->id,
                    'date' => $today,
                    'scanned_at' => $currentTime,
                    'entry_at' => $currentTime,
                    'status' => $status,
                    'source' => AttendanceSource::NFC,
                ]);

                $this->recordRecentReading($studentId, $credentialId, 'entry', $message);
            });

            $this->dispatchGuardianNotifications($enrollment, $studentData, $currentTime, 'entry');

            return $payload + [
                'event' => 'card_inserted',
                'credential_id' => $credentialId,
                'status' => 'ok',
                'student' => array_merge($studentData, [
                    'type' => 'entry',
                    'attendance_status' => $status,
                ]),
                'message' => $message,
            ];
        }

        if (! $todayAttendance->exit_at && $this->rules->canRegisterExit($currentTime)) {
            $message = 'Salida registrada.';

            DB::transaction(function () use (
                $todayAttendance,
                $currentTime,
                $studentId,
                $credentialId,
                $message
            ): void {
                $todayAttendance->update(['exit_at' => $currentTime]);
                $this->recordRecentReading($studentId, $credentialId, 'exit', $message);
            });

            $this->dispatchGuardianNotifications($enrollment, $studentData, $currentTime, 'exit');

            return $payload + [
                'event' => 'card_inserted',
                'credential_id' => $credentialId,
                'status' => 'ok',
                'student' => array_merge($studentData, ['type' => 'exit']),
                'message' => $message,
            ];
        }

        if (! $todayAttendance->exit_at) {
            $message = 'Entrada ya registrada.';
            $this->recordRecentReading($studentId, $credentialId, 'identify', $message);

            return $payload + [
                'event' => 'card_inserted',
                'credential_id' => $credentialId,
                'status' => 'info',
                'student' => array_merge($studentData, ['type' => 'identify']),
                'message' => $message,
            ];
        }

        $message = 'Asistencia completa.';
        $this->recordRecentReading($studentId, $credentialId, 'identify', $message);

        return $payload + [
            'event' => 'card_inserted',
            'credential_id' => $credentialId,
            'status' => 'info',
            'student' => array_merge($studentData, ['type' => 'identify']),
            'message' => $message,
        ];
    }

    private function recordRecentReading(int $studentId, ?string $credentialId, string $event, string $message): void
    {
        RecentReading::create([
            'student_id' => $studentId,
            'read_at' => $this->rules->now(),
            'event' => $event,
            'message' => $message,
            'credential_id' => $credentialId,
        ]);
    }

    private function buildStudentData(Enrollment $enrollment): array
    {
        $grade = $enrollment->classGroup?->gradeLevel?->name;
        $group = $enrollment->classGroup?->name;
        $name = trim(
            ($enrollment->student->profile?->first_name ?? '').' '.
                ($enrollment->student->profile?->last_name ?? '')
        );

        return [
            'id' => $enrollment->student->id,
            'credential_id' => $enrollment->student->credential_id,
            'name' => $name,
            'photo_url' => $this->photoPathService->signedUrl($enrollment->student, 'profile'),
            'gender' => $enrollment->student->profile?->gender,
            'grade' => $grade,
            'group' => $group,
            'registered_at' => $this->rules->now()->toIso8601String(),
        ];
    }

    private function dispatchGuardianNotifications(Enrollment $enrollment, array $studentData, \DateTimeInterface $registrationTime, string $type): void
    {
        $guardians = $enrollment->student
            ->guardians()
            ->whereNotNull('telegram_id')
            ->get();

        foreach ($guardians as $guardian) {
            SendTelegramNotificationJob::dispatch(
                $guardian->telegram_id,
                $studentData,
                $registrationTime,
                $type
            );
        }
    }
}
