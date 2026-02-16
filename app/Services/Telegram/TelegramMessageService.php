<?php

namespace App\Services\Telegram;

use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\FileUpload\InputFile;
use App\Enums\Emojis;

class TelegramMessageService
{
    public function welcome(int $chatId): void
    {
        Telegram::sendPhoto([
            'chat_id' => $chatId,
            'photo' => InputFile::create(
                storage_path('app/private/banners/Black_Banner.png')
            ),
            'caption' =>
            Emojis::BELL . " <b>Vincule su cuenta de Telegram</b>\n\n" .
                "Reciba notificaciones sobre:\n\n" .
                Emojis::PIN . "Asistencias y retardos\n" .
                Emojis::LOUD_SPEAKER . "Avisos importantes\n" .
                Emojis::PARTY_POPPER . "Eventos escolares\n\n" .
                "<i>Si ya tiene esta cuenta vinculada, ignore este mensaje</i>",

            'parse_mode' => 'HTML',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => Emojis::CHECK_MARK_BUTTON . ' Iniciar Registro', 'callback_data' => 'begin']]
                ]
            ])
        ]);
    }



    public function requestCurp(int $chatId): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => Emojis::PENCIL . " *Ingrese la CURP del estudiante*\n\n" .
                "Por favor, escriba la CURP completa (18 caracteres) del alumno que desea vincular.\n\n" .
                "_Ejemplo: ABCD123456HDFRNN09_",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => Emojis::X_BUTTON . 'Cancelar', 'callback_data' => 'cancel']]
                ]
            ])
        ]);
    }

    public function showGuardians(int $chatId, $student, array $relatedStudents): void
    {
        $keyboard = [];
        $enrollment = $student->student->currentEnrollment;
        $gradeInfo = '';

        if ($enrollment && $enrollment->classGroup) {
            $grade = $enrollment->classGroup->gradeLevel->name ?? '';
            $group = $enrollment->classGroup->name ?? '';
            $gradeInfo = "📚 Grado: {$grade} {$group}\n";
        }

        foreach ($student->student->guardians as $guardian) {
            $isRegistered = $guardian->telegram_id && $guardian->telegram_id !== $chatId;
            $keyboard[] = [[
                'text' => $isRegistered
                    ? Emojis::X_BUTTON . "{$guardian->profile->first_name} {$guardian->profile->last_name} (Ya registrado)"
                    : Emojis::CHECK_MARK_BUTTON . " Soy {$guardian->profile->first_name} {$guardian->profile->last_name}",
                'callback_data' => $isRegistered
                    ? 'guardian_registered'
                    : "select_guardian:{$guardian->id}"
            ]];
        }

        $text = Emojis::CHECK_MARK_BUTTON . " *Estudiante encontrado*\n\n" .
            Emojis::MAN_STUDENT . " Alumno: {$student->first_name} {$student->last_name}\n" .
            $gradeInfo . "\n" .
            "Seleccione su relación con el estudiante:";

        if (count($relatedStudents) > 0) {
            $text .= "\n\n_" . Emojis::INFORMATION_BUTTON . " También se vinculará automáticamente con:_\n";
            foreach ($relatedStudents as $s) {
                $text .= "• {$s->profile->first_name} {$s->profile->last_name}\n";
            }
        }

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $text,
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode(['inline_keyboard' => $keyboard])
        ]);
    }

    public function studentFound(
        int $chatId,
        $student,
        $session,
        array $relatedStudents
    ): void {
        $text =
            Emojis::MAN_STUDENT . " Alumno: {$student->first_name} {$student->last_name}\n" .
            Emojis::BOOK . " Grado: {$session->grade} {$session->group}\n\n";

        if (count($relatedStudents)) {
            $text .= Emojis::FAMILY . " También encontramos otros alumnos asociados:\n";
            foreach ($relatedStudents as $s) {
                $text .= "• {$s->profile->first_name} {$s->profile->last_name}\n";
            }
            $text .= "\n" . Emojis::INFORMATION_BUTTON . " No es necesario hacer otro registro.";
        } else {
            $text .=
                Emojis::INFORMATION_BUTTON . "Si tiene más hijos y no aparecen aquí, " .
                "use la opción " . Emojis::PLUS_BUTTON . " Nuevo registro.";
        }

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $text
        ]);
    }

    public function studentAlreadyRegistered(
        int $chatId,
        $student,
        $session
    ): void {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' =>
            Emojis::INFORMATION_BUTTON . "El alumno {$student->first_name} {$student->last_name} " .
                "ya está vinculado a una cuenta de Telegram.\n\n" .
                "Si desea registrar otro alumno, use " . Emojis::PLUS_BUTTON . " Nuevo registro."
        ]);
    }


    public function sendNotifications(int $chatId): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => 'Notificaciones habilitadas ✅'
        ]);
    }

    /* ----------  ---------- */

    public function requireButton(int $chatId): void
    {
        Telegram::sendMessage(['chat_id' => $chatId, 'text' => 'Use los botones 👆']);
    }

    public function requireText(int $chatId): void
    {
        Telegram::sendMessage(['chat_id' => $chatId, 'text' => 'Ingrese el texto solicitado']);
    }

    public function invalidOption(int $chatId): void
    {
        Telegram::sendMessage(['chat_id' => $chatId, 'text' => 'Opción inválida']);
    }

    public function invalidCurp(int $chatId): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => Emojis::X_BUTTON . " *CURP inválida*\n\n" .
                "La CURP debe tener 18 caracteres y seguir el formato oficial.\n\n" .
                "Por favor, verifique e intente nuevamente.",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => Emojis::X_BUTTON . ' Cancelar', 'callback_data' => 'cancel']]
                ]
            ])
        ]);
    }

    public function studentNotFound(int $chatId): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => Emojis::X_BUTTON . " *Estudiante no encontrado*\n\n" .
                "No se encontró ningún estudiante activo con esa CURP.\n\n" .
                "*Posibles causas:*\n" .
                "• La CURP no está registrada en el sistema\n" .
                "• El estudiante no tiene una inscripción activa\n" .
                "• Puede haber un error de captura\n\n" .
                "Por favor, verifique la CURP con la escuela.",
            'parse_mode' => 'Markdown'
        ]);
    }

    public function guardianAlreadyRegistered(int $chatId): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => Emojis::WARNING . " *Tutor ya registrado*\n\n" .
                "Este tutor ya está vinculado a otra cuenta de Telegram.\n\n" .
                "Si necesita cambiar la vinculación, contacte con la administración escolar.",
            'parse_mode' => 'Markdown'
        ]);
    }

    public function registrationSuccess(int $chatId, $guardian): void
    {
        $students = $guardian->students;
        $studentList = $students->map(fn($s) => "• {$s->profile->first_name} {$s->profile->last_name}")->join("\n");

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => Emojis::CHECK_MARK_BUTTON . " *¡Cuenta vinculada exitosamente!*\n\n" .
                Emojis::BELL . " A partir de ahora recibirá notificaciones sobre:\n" .
                $studentList . "\n\n" .
                "*Tipos de notificaciones:*\n" .
                "• Asistencias y retardos de entradas y salidas\n" .
                //"• Avisos importantes\n" .
                //"• Eventos escolares\n\n" .
                "¿Tiene más hijos en la escuela?",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => Emojis::PLUS_BUTTON . ' Agregar otro estudiante', 'callback_data' => 'add_another']],
                    [['text' => Emojis::CHECK_MARK_BUTTON . ' Finalizar', 'callback_data' => 'done']]
                ]
            ])
        ]);
    }

    public function completedMenu(int $chatId): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => Emojis::WAVING_HAND . " *Gracias por usar nuestro sistema*\n\n" .
                "Su cuenta está lista para recibir notificaciones.\n\n" .
                "¿Necesita vincular otro estudiante?",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => Emojis::PLUS_BUTTON . ' Agregar otro estudiante', 'callback_data' => 'add_another']]
                ]
            ])
        ]);
    }
    public function telegramAccountLinkedToAnother(int $chatId): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => Emojis::WARNING . " *Cuenta de Telegram ya usada*\n\n" .
                "Esta cuenta de Telegram ya está vinculada a otro tutor en el sistema.\n\n" .
                "Si usted no realizó este registro, por favor repórtelo a la institución.\n" .
                "Si intenta vincularse con otro estudiante, asegúrese de usar la misma cuenta para ambos.",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => Emojis::X_BUTTON . ' Cancelar', 'callback_data' => 'cancel']]
                ]
            ])
        ]);
    }

    public function cancelMenu(int $chatId): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => Emojis::WAVING_HAND . " *Gracias por usar nuestro sistema*\n\n" .
                "¿Necesita vincular otro estudiante?",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => Emojis::PLUS_BUTTON . ' Agregar otro estudiante', 'callback_data' => 'add_another']]
                ]
            ])
        ]);
    }
}
