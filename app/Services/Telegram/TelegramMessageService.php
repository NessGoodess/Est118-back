<?php

namespace App\Services\Telegram;

use Telegram\Bot\Laravel\Facades\Telegram;
use Telegram\Bot\FileUpload\InputFile;

class TelegramMessageService
{
    public function welcome(int $chatId): void
    {
        Telegram::sendPhoto([
            'chat_id' => $chatId,
            'photo' => InputFile::create(
                storage_path('app/private/banners/Black_Banner.png')
            ),
            'caption' => "*🎓 Bienvenido al Sistema de Notificaciones*\n" .
                "*Escuela Secundaria Técnica 118*\n\n" .
                "Vincule su cuenta de Telegram para recibir notificaciones sobre:\n" .
                "• Asistencias y retardos\n" .
                "• Avisos importantes\n" .
                "• Eventos escolares\n\n" .
                "Presione el botón para comenzar el registro.",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '✅ Iniciar Registro', 'callback_data' => 'begin']]
                ]
            ])
        ]);
    }



    public function requestCurp(int $chatId): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "📝 *Ingrese la CURP del estudiante*\n\n" .
                "Por favor, escriba la CURP completa (18 caracteres) del alumno que desea vincular.\n\n" .
                "_Ejemplo: ABCD123456HDFRNN09_",
            'parse_mode' => 'Markdown'
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
                    ? "❌ {$guardian->profile->first_name} {$guardian->profile->last_name} (Ya registrado)"
                    : "✅ Soy {$guardian->profile->first_name} {$guardian->profile->last_name}",
                'callback_data' => $isRegistered
                    ? 'guardian_registered'
                    : "select_guardian:{$guardian->id}"
            ]];
        }

        $text = "✅ *Estudiante encontrado*\n\n" .
            "👨‍🎓 Alumno: {$student->first_name} {$student->last_name}\n" .
            $gradeInfo . "\n" .
            "Seleccione su relación con el estudiante:";

        if (count($relatedStudents) > 0) {
            $text .= "\n\n_ℹ️ También se vinculará automáticamente con:_\n";
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
            "👨‍🎓 Alumno: {$student->first_name} {$student->last_name}\n" .
            "📚 Grado: {$session->grade} {$session->group}\n\n";

        if (count($relatedStudents)) {
            $text .= "👨‍👩‍👧 También encontramos otros alumnos asociados:\n";
            foreach ($relatedStudents as $s) {
                $text .= "• {$s->profile->first_name} {$s->profile->last_name}\n";
            }
            $text .= "\nℹ️ No es necesario hacer otro registro.";
        } else {
            $text .=
                "ℹ️ Si tiene más hijos y no aparecen aquí, " .
                "use la opción ➕ Nuevo registro.";
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
            "ℹ️ El alumno {$student->first_name} {$student->last_name} " .
                "ya está vinculado a una cuenta de Telegram.\n\n" .
                "Si desea registrar otro alumno, use ➕ Nuevo registro."
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
            'text' => "❌ *CURP inválida*\n\n" .
                "La CURP debe tener 18 caracteres y seguir el formato oficial.\n\n" .
                "Por favor, verifique e intente nuevamente.",
            'parse_mode' => 'Markdown'
        ]);
    }

    public function studentNotFound(int $chatId): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "❌ *Estudiante no encontrado*\n\n" .
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
            'text' => "⚠️ *Tutor ya registrado*\n\n" .
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
            'text' => "✅ *¡Cuenta vinculada exitosamente!*\n\n" .
                "🔔 A partir de ahora recibirá notificaciones sobre:\n" .
                $studentList . "\n\n" .
                "*Tipos de notificaciones:*\n" .
                "• Asistencias y retardos\n" .
                "• Avisos importantes\n" .
                "• Eventos escolares\n\n" .
                "¿Tiene más hijos en la escuela?",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '➕ Agregar otro estudiante', 'callback_data' => 'add_another']],
                    [['text' => '✅ Finalizar', 'callback_data' => 'done']]
                ]
            ])
        ]);
    }

    public function completedMenu(int $chatId): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "👋 *Gracias por usar nuestro sistema*\n\n" .
                "Su cuenta está lista para recibir notificaciones.\n\n" .
                "¿Necesita vincular otro estudiante?",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => '➕ Agregar otro estudiante', 'callback_data' => 'add_another']]
                ]
            ])
        ]);
    }
}