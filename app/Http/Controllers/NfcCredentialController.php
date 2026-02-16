<?php

namespace App\Http\Controllers;

use App\Enums\AttendanceSource;
use App\Events\CredentialReadEvent;
use App\Jobs\SendTelegramNotificationJob;
use App\Models\Enrollment;
use App\Models\GeneralAttendance;
use App\Models\RecentReading;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NfcCredentialController extends Controller
{
    private const ENTRY_LATE_CUTOFF = '08:00';

    private const EXIT_EARLIEST = '13:30';

    /**
     * Handle NFC credential read events from the reader.
     */
    public function read(Request $request)
    {
        $data = $request->all();
        $eventType = $data['event'] ?? null;

        $payload = [
            'reader' => $data['reader'] ?? 'NFC Reader',
            'timestamp' => now()->toIso8601String(),
        ];

        try {
            switch ($eventType) {
                case 'card_inserted':
                    $payload = $this->handleCardInserted($data, $payload);
                    break;

                case 'card_removed':
                    $payload = $this->handleCardRemoved($payload);
                    break;

                default:
                    $payload = $this->handleUnknownEvent($payload);
            }

            broadcast(new CredentialReadEvent($payload));

            return response()->json(['status' => 'ok', 'payload' => $payload]);
        } catch (\Throwable $e) {
            Log::error('Error processing NFC credential read', [
                'event' => $eventType,
                'error' => $e->getMessage(),
            ]);

            broadcast(new CredentialReadEvent([
                'event' => 'error',
                'status' => 'error',
                'message' => 'Error interno: '.$e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ]));

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle card inserted event.
     */
    private function handleCardInserted(array $data, array $payload): array
    {
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

        $today = now()->toDateString();
        $currentTime = now();
        $student = $enrollment->student;

        $todayAttendance = GeneralAttendance::where('student_id', $student->id)
            ->where('date', $today)
            ->first();

        $studentData = $this->buildStudentData($enrollment);

        if (! $todayAttendance) {
            $status = $this->isLateEntry($currentTime) ? 'late' : 'present';
            $attendance = GeneralAttendance::create([
                'student_id' => $student->id,
                'academic_year_id' => $enrollment->academicYear->id,
                'date' => $today,
                'scanned_at' => $currentTime,
                'entry_at' => $currentTime,
                'status' => $status,
                'source' => AttendanceSource::NFC,
            ]);

            $this->recordRecentReading($student->id, $credentialId, 'entry', $status === 'late' ? 'Entrada tardía registrada' : 'Entrada registrada.');
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

                $this->recordRecentReading($student->id, $credentialId, 'exit', 'Salida registrada.');
                $this->dispatchGuardianNotifications($enrollment, $studentData, $currentTime, 'exit');

                return $payload + [
                    'event' => 'card_inserted',
                    'credential_id' => $credentialId,
                    'status' => 'ok',
                    'student' => array_merge($studentData, ['type' => 'exit']),
                    'message' => 'Salida registrada.',
                ];
            }

            $this->recordRecentReading($student->id, $credentialId, 'ignored', 'No es horario de salida.');

            return $payload + [
                'event' => 'card_inserted',
                'credential_id' => $credentialId,
                'status' => 'warning',
                'student' => $studentData,
                'message' => 'No es horario de salida. Intente después de las '.self::EXIT_EARLIEST,
            ];
        }

        $this->recordRecentReading($student->id, $credentialId, 'duplicate', 'Ya tiene registro completo hoy.');

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

    /**
     * Build student data array from enrollment.
     */
    private function buildStudentData(Enrollment $enrollment): array
    {
        $grade = $enrollment->classGroup?->gradeLevel?->name;
        $group = $enrollment->classGroup?->name;
        $photo = $enrollment->student->profile?->profile_picture;

        $photoPath = ($grade && $group && $photo)
            ? 'photos/students/'.rawurlencode($grade).'/'.rawurlencode($group).'/'.rawurlencode($photo)
            : 'photos/students/default.png';

        return [
            'id' => $enrollment->student->id,
            'credential_id' => $enrollment->student->credential_id,
            'name' => $enrollment->student->profile->first_name.' '.$enrollment->student->profile->last_name,
            'photo_url' => $photoPath,
            'grade' => $grade,
            'group' => $group,
            'registered_at' => now()->toIso8601String(),
        ];
    }

    /**
     * Dispatch Telegram notifications to guardians.
     */
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

    private function handleCardRemoved(array $payload): array
    {
        return $payload + [
            'event' => 'card_removed',
            'status' => 'info',
            'message' => 'Tarjeta retirada. Esperando nueva credencial...',
            'student' => null,
        ];
    }

    private function handleUnknownEvent(array $payload): array
    {
        return $payload + [
            'event' => 'unknown',
            'status' => 'error',
            'message' => 'Evento no reconocido o no enviado por el lector.',
            'student' => null,
        ];
    }
}
