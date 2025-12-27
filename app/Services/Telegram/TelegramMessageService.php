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
            'caption' => "*Bienvenido a la Técnica 118*\n\nPresione el botón para comenzar.",
            'parse_mode' => 'Markdown',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => 'Nuevo Registro', 'callback_data' => 'begin']]
                ]
            ])
        ]);
    }

    public function sendGrades(int $chatId): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => 'Seleccione su grado:',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [
                        ['text' => '1°', 'callback_data' => '1°'],
                        ['text' => '2°', 'callback_data' => '2°'],
                        ['text' => '3°', 'callback_data' => '3°'],
                    ]
                ]
            ])
        ]);
    }

    public function sendGroups(int $chatId): void
    {
        $groups = array_chunk(range('A', 'H'), 4);

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => 'Seleccione su grupo:',
            'reply_markup' => json_encode([
                'inline_keyboard' => array_map(
                    fn($row) => array_map(
                        fn($g) => ['text' => $g, 'callback_data' => $g],
                        $row
                    ),
                    $groups
                )
            ])
        ]);
    }

    public function requestCurp(int $chatId): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => 'Ingrese la CURP del alumno'
        ]);
    }

    public function showGuardians(int $chatId, $student, $session): void
    {
        $keyboard = [];

        foreach ($student->student->guardians as $guardian) {
            $keyboard[] = [[
                'text' => $guardian->telegram_id
                    ? "❌ {$guardian->profile->first_name} {$guardian->profile->last_name}"
                    : "👤 Soy {$guardian->profile->first_name} {$guardian->profile->last_name}",
                'callback_data' => $guardian->telegram_id
                    ? 'guardian_registered'
                    : "select_guardian:{$guardian->id}"
            ]];
        }

        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => "Alumno: {$student->first_name} {$student->last_name}\nGrado: {$session->grade} {$session->group}",
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
        Telegram::sendMessage(['chat_id' => $chatId, 'text' => 'CURP inválida']);
    }

    public function studentNotFound(int $chatId): void
    {
        Telegram::sendMessage(['chat_id' => $chatId, 'text' => 'Alumno no encontrado']);
    }

    public function guardianAlreadyRegistered(int $chatId): void
    {
        Telegram::sendMessage(['chat_id' => $chatId, 'text' => 'Tutor ya registrado']);
    }

    public function registrationSuccess(int $chatId): void
    {
        Telegram::sendMessage(['chat_id' => $chatId, 'text' => '✅ Registro exitoso']);
    }

    public function completedMenu(int $chatId): void
    {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => '¿Qué desea hacer?',
            'reply_markup' => json_encode([
                'inline_keyboard' => [
                    [['text' => 'Nuevo registro', 'callback_data' => 'begin']]
                ]
            ])
        ]);
    }

    public function gradeSelected(int $chatId, string $grade): void
    {
        Telegram::sendMessage(['chat_id' => $chatId, 'text' => "Grado $grade seleccionado"]);
    }

    public function groupSelected(int $chatId, string $group): void
    {
        Telegram::sendMessage(['chat_id' => $chatId, 'text' => "Grupo $group seleccionado"]);
    }
}
