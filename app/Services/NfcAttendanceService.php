<?php

namespace App\Services;

use App\Enums\AttendanceSource;
use App\Jobs\SendTelegramNotificationJob;
use App\Models\Enrollment;
use App\Models\GeneralAttendance;
use App\Models\RecentReading;
use Illuminate\Support\Facades\Cache;

/**
 * Business logic for NFC card_inserted events (entry/exit attendance).
 */
class NfcAttendanceService
{
    private const ENTRY_LATE_CUTOFF = '07:00';

    private const EXIT_EARLIEST = '13:30';

    public function __construct(
        private readonly NfcReaderSlotService $slotService,
        private readonly StudentPhotoPathService $photoPathService
    ) {}

    public function processCardInserted(array $data): array
    {
        $payload = $this->slotService->enrichPayload($data, [
            'reader' => $data['reader'] ?? 'NFC Reader',
            'timestamp' => now()->toIso8601String(),
        ]);

        if (array_key_exists('reader_armed', $payload) && $payload['reader_armed'] === false) {
            return $payload + [
                'event' => 'card_inserted',
                'status' => 'warning',
                'message' => 'Lector en pausa. Active las lecturas para registrar asistencia.',
                'student' => null,
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

        $enrollment = Enrollment::with([
            'student',
            'student.profile',
            'classGroup.gradeLevel',
            'academicYear:id',
        ])
            ->where('status', 'active')
            ->whereHas('student', fn ($q) => $q->where('credential_id', $credentialId))
            ->first();

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
            if (Cache::has("{$lockKey}:recent")) {
                return $payload + [
                    'event' => 'card_inserted',
                    'credential_id' => $credentialId,
                    'status' => 'info',
                    'student' => $studentData,
                    'message' => 'Lectura repetida ignorada.',
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

    private function processAttendance(
        Enrollment $enrollment,
        int $studentId,
        string $credentialId,
        array $payload,
        array $studentData
    ): array {
        $today = now()->toDateString();
        $currentTime = now();

        $todayAttendance = GeneralAttendance::query()
            ->where('student_id', $studentId)
            ->where('date', $today)
            ->first();

        if (! $todayAttendance) {
            $status = $this->isLateEntry($currentTime) ? 'late' : 'present';
            GeneralAttendance::create([
                'student_id' => $studentId,
                'academic_year_id' => $enrollment->academicYear->id,
                'date' => $today,
                'scanned_at' => $currentTime,
                'entry_at' => $currentTime,
                'status' => $status,
                'source' => AttendanceSource::NFC,
            ]);

            $this->recordRecentReading($studentId, $credentialId, 'entry', $status === 'late' ? 'Entrada tardía registrada' : 'Entrada registrada.');
            $this->dispatchGuardianNotifications($enrollment, $studentData, $currentTime, 'entry');

            $message = $status === 'late' ? 'Entrada tardía registrada' : 'Tarjeta reconocida correctamente.';

            return $payload + [
                'event' => 'card_inserted',
                'credential_id' => $credentialId,
                'status' => 'ok',
                'student' => array_merge($studentData, ['type' => 'entry']),
                'message' => $message,
            ];
        }

        if (! $todayAttendance->exit_at) {
            if ($this->canRegisterExit($currentTime)) {
                $todayAttendance->update(['exit_at' => $currentTime]);

                $this->recordRecentReading($studentId, $credentialId, 'exit', 'Salida registrada.');
                $this->dispatchGuardianNotifications($enrollment, $studentData, $currentTime, 'exit');

                return $payload + [
                    'event' => 'card_inserted',
                    'credential_id' => $credentialId,
                    'status' => 'ok',
                    'student' => array_merge($studentData, ['type' => 'exit']),
                    'message' => 'Salida registrada.',
                ];
            }

            $this->recordRecentReading($studentId, $credentialId, 'ignored', 'No es horario de salida.');

            return $payload + [
                'event' => 'card_inserted',
                'credential_id' => $credentialId,
                'status' => 'warning',
                'student' => $studentData,
                'message' => 'No es horario de salida. Intente después de las '.self::EXIT_EARLIEST,
            ];
        }

        $this->recordRecentReading($studentId, $credentialId, 'duplicate', 'Ya tiene registro completo hoy.');

        return $payload + [
            'event' => 'card_inserted',
            'credential_id' => $credentialId,
            'status' => 'info',
            'student' => $studentData,
            'message' => 'Ya tiene registro completo hoy.',
        ];
    }

    private function isLateEntry(\DateTimeInterface $time): bool
    {
        $cutoff = \DateTime::createFromFormat('H:i', self::ENTRY_LATE_CUTOFF);
        $compare = \DateTime::createFromFormat('H:i', $time->format('H:i'));

        return $compare > $cutoff;
    }

    private function canRegisterExit(\DateTimeInterface $time): bool
    {
        $earliest = \DateTime::createFromFormat('H:i', self::EXIT_EARLIEST);
        $compare = \DateTime::createFromFormat('H:i', $time->format('H:i'));

        return $compare >= $earliest;
    }

    private function recordRecentReading(int $studentId, ?string $credentialId, string $event, string $message): void
    {
        RecentReading::create([
            'student_id' => $studentId,
            'read_at' => now(),
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
            'registered_at' => now()->toIso8601String(),
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
