<?php

namespace App\Http\Controllers;

use App\Events\CredentialReadEvent;
use App\Models\Enrollment;
use App\Models\Student;
use Illuminate\Http\Request;
use Telegram\Bot\FileUpload\InputFile;
use Telegram\Bot\Laravel\Facades\Telegram;

class NfcCredentialController extends Controller
{
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
                // Card inserted event
                case 'card_inserted':
                    $credentialId = $data['credential_id'] ?? null;

                    if (!$credentialId || $credentialId === 'Null') {
                        $payload += [
                            'event' => 'card_inserted',
                            'status' => 'warning',
                            'message' => 'Credencial vacía o ilegible.',
                            'student' => null,
                        ];
                        break;
                    }

                    $studentFound = Enrollment::with([
                        'student',
                        'student.profile',
                        'classGroup.gradeLevel'
                    ])
                        ->whereHas('student', fn($q) => $q->where('credential_id', $credentialId))
                        ->first();

                    if (!$studentFound) {
                        $payload += [
                            'event' => 'card_inserted',
                            'credential_id' => $credentialId,
                            'status' => 'warning',
                            'message' => 'Credencial no registrada.',
                            'student' => null,
                        ];
                        break;
                    }

                    $studentGrade = $studentFound->classGroup?->gradeLevel?->name;
                    $studentGroup = $studentFound->classGroup?->name;
                    $studentPhoto = $studentFound->student->profile->profile_picture;

                    if ($studentGrade && $studentGroup && $studentPhoto) {
                        $path = 'photos/students/' .
                            rawurlencode($studentGrade) . '/' .
                            rawurlencode($studentGroup) . '/' .
                            rawurlencode($studentPhoto);
                    } else {
                        $path = "photos/students/default.png";
                    }

                    $foto_link = asset("storage/$path");

                    $registrationTimestamp = now();

                    $student = $studentFound ?
                        [
                            'id' => $studentFound->id,
                            'credential_id' =>  $studentFound->student->credential_id,
                            'name' => $studentFound->student->profile->first_name . ' ' . $studentFound->student->profile->last_name,
                            'photo_url' => $foto_link,
                            'grade' => $studentGrade,
                            'group' => $studentGroup,
                            'registered_at' => $registrationTimestamp,
                        ] : null;


                    $payload += [
                        'event' => 'card_inserted',
                        'credential_id' => $credentialId,
                        'status' => 'ok',
                        'student' => $student,
                        'message' => 'Tarjeta reconocida correctamente.'
                    ];

                    /**
                     * Send notification to guardians
                     */
                    $guardians = Student::find($studentFound->id)?->guardians()
                        ->whereNotNull('telegram_id')
                        ->get();
                    $photoPath = storage_path('app/private/banners/Black_Banner.png');
                    foreach ($guardians as $guardian) {
                        Telegram::sendPhoto([
                            'chat_id' => $guardian->telegram_id,
                            'photo' => InputFile::create($photoPath),
                            'parse_mode' => 'HTML',
                            'caption' => "
👋 <b>Estimado(a) padre/madre de familia:</b>

Le informamos que su hijo(a):

<b>👨‍🎓 {$student['name']}</b>
<b>📘 Grado y Grupo:</b> {$student['grade']} “{$student['group']}”

<b>🕘 Hora de ingreso:</b> 08:12 a.m. {$registrationTimestamp->format('H:i:s')}
<b>📅 Fecha:</b> 15 de diciembre de 2025 {$registrationTimestamp->format('Y-m-d')}

<b>✅ Asistencia registrada correctamente.</b>

<i>Seguimos trabajando por la seguridad y el bienestar de nuestros alumnos.</i>"
                        ]);
                    }
                    break;

                // Card removed event
                case 'card_removed':
                    $payload += [
                        'event' => 'card_removed',
                        'status' => 'info',
                        'message' => 'Tarjeta retirada. Esperando nueva credencial...',
                        'student' => null,
                    ];
                    break;

                default:
                    $payload += [
                        'event' => 'unknown',
                        'status' => 'error',
                        'message' => 'Evento no reconocido o no enviado por el lector.',
                        'student' => null,
                    ];
            }

            broadcast(new CredentialReadEvent($payload))->toOthers();

            return response()->json(['status' => 'ok', 'payload' => $payload]);
        } catch (\Throwable $e) {
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
}
