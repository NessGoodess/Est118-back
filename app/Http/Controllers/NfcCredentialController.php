<?php

namespace App\Http\Controllers;

use App\Events\CredentialReadEvent;
use App\Jobs\SendTelegramNotificationJob;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class NfcCredentialController extends Controller
{
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

            broadcast(new CredentialReadEvent($payload))->toOthers();

            return response()->json(['status' => 'ok', 'payload' => $payload]);
        } catch (\Throwable $e) {
            Log::error('Error processing NFC credential read', [
                'event' => $eventType,
                'error' => $e->getMessage(),
            ]);

            broadcast(new CredentialReadEvent([
                'event' => 'error',
                'status' => 'error',
                'message' => 'Error interno: ' . $e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ]))->toOthers();

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Handle card inserted event.
     */
    private function handleCardInserted(array $data, array $payload): array
    {
        $credentialId = $data['credential_id'] ?? null;

        if (!$credentialId || $credentialId === 'Null') {
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
            'classGroup.gradeLevel'
        ])
            ->whereHas('student', fn($q) => $q->where('credential_id', $credentialId))
            ->first();

        if (!$enrollment) {
            return $payload + [
                'event' => 'card_inserted',
                'credential_id' => $credentialId,
                'status' => 'warning',
                'message' => 'Credencial no registrada.',
                'student' => null,
            ];
        }

        $student = $this->buildStudentData($enrollment);
        $registrationTime = now();

        // Dispatch Telegram notifications to guardians via queue
        $this->dispatchGuardianNotifications($enrollment, $student, $registrationTime);

        return $payload + [
            'event' => 'card_inserted',
            'credential_id' => $credentialId,
            'status' => 'ok',
            'student' => $student,
            'message' => 'Tarjeta reconocida correctamente.'
        ];
    }

    /**
     * Build student data array from enrollment.
     */
    private function buildStudentData(Enrollment $enrollment): array
    {
        $grade = $enrollment->classGroup?->gradeLevel?->name;
        $group = $enrollment->classGroup?->name;
        $photo = $enrollment->student->profile->profile_picture;

        $photoPath = ($grade && $group && $photo)
            ? 'photos/students/' . rawurlencode($grade) . '/' . rawurlencode($group) . '/' . rawurlencode($photo)
            : 'photos/students/default.png';

        return [
            'id' => $enrollment->id,
            'credential_id' => $enrollment->student->credential_id,
            'name' => $enrollment->student->profile->first_name . ' ' . $enrollment->student->profile->last_name,
            'photo_url' => asset("storage/{$photoPath}"),
            'grade' => $grade,
            'group' => $group,
            'registered_at' => now(),
        ];
    }

    /**
     * Dispatch Telegram notifications to all guardians.
     */
    private function dispatchGuardianNotifications(Enrollment $enrollment, array $studentData, \DateTimeInterface $registrationTime): void
    {
        $guardians = Student::find($enrollment->id)
            ?->guardians()
            ->whereNotNull('telegram_id')
            ->get() ?? collect();

        foreach ($guardians as $guardian) {
            SendTelegramNotificationJob::dispatch(
                $guardian->telegram_id,
                $studentData,
                $registrationTime
            );
        }
    }

    /**
     * Handle card removed event.
     */
    private function handleCardRemoved(array $payload): array
    {
        return $payload + [
            'event' => 'card_removed',
            'status' => 'info',
            'message' => 'Tarjeta retirada. Esperando nueva credencial...',
            'student' => null,
        ];
    }

    /**
     * Handle unknown event.
     */
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
