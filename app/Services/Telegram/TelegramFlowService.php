<?php

namespace App\Services\Telegram;

use App\Enums\SessionStep;
use App\Models\Guardian;
use App\Models\TelegramSession;
use Telegram\Bot\Laravel\Facades\Telegram;

class TelegramFlowService
{
    public function __construct(
        private TelegramMessageService $messages,
        private TelegramDomainService $domain
    ) {}

    public function handle($update): void
    {
        try{
        $chatId = $this->getChatId($update);

        $session = TelegramSession::firstOrCreate(
            ['chat_id' => $chatId],
            ['step' => SessionStep::START]
        );

        match ($session->step) {
            SessionStep::START      => $this->start($session, $update),
            SessionStep::ENTER_CURP => $this->enterCurp($session, $update),
            SessionStep::CONFIRM    => $this->confirmGuardian($session, $update),
            SessionStep::COMPLETED  => $this->completed($session, $update),
        };
    } catch (\Exception $e) {
        Telegram::sendMessage([
            'chat_id' => $chatId,
            'text' => $e->getMessage(),
        ]);
    }
    }

    private function getChatId($update): int
    {
        return $update->getMessage()
            ? $update->getMessage()->getChat()->getId()
            : $update->getCallbackQuery()->getMessage()->getChat()->getId();
    }

    /* -------------------- STEPS -------------------- */

    private function start(TelegramSession $session, $update): void
    {
        if ($update->getCallbackQuery()) {
            $data = $update->getCallbackQuery()->getData();
            
            if ($data === 'begin' || $data === 'add_another') {
                $session->update(['step' => SessionStep::ENTER_CURP]);
                $this->messages->requestCurp($session->chat_id);
                return;
            }
        }

        $this->messages->welcome($session->chat_id);
    }

    private function enterCurp(TelegramSession $session, $update): void
    {
        if (!$update->getMessage()) {
            $this->messages->requireText($session->chat_id);
            return;
        }

        $curp = strtoupper($update->getMessage()->getText());

        if (!$this->domain->isValidCurp($curp)) {
            $this->messages->invalidCurp($session->chat_id);
            return;
        }

        $student = $this->domain->findStudentByCurp($curp);

        if (!$student) {
            $this->messages->studentNotFound($session->chat_id);
            return;
        }

        $relatedStudents = $this->domain->getOtherStudentsForTutor($student);

        $session->update([
            'step' => SessionStep::CONFIRM,
            'curp' => $curp
        ]);

        $this->messages->showGuardians(
            $session->chat_id,
            $student,
            $relatedStudents
        );
    }

    private function confirmGuardian(TelegramSession $session, $update): void
    {
        if (!$update->getCallbackQuery()) {
            $this->messages->requireButton($session->chat_id);
            return;
        }

        $data = $update->getCallbackQuery()->getData();

        if ($data === 'guardian_registered') {
            $this->messages->guardianAlreadyRegistered($session->chat_id);
            return;
        }

        if (!str_starts_with($data, 'select_guardian:')) {
            $this->messages->invalidOption($session->chat_id);
            return;
        }

        [, $guardianId] = explode(':', $data);

        $guardian = Guardian::find($guardianId);

        if (!$guardian) {
            $this->messages->invalidOption($session->chat_id);
            return;
        }

        // Si el tutor ya tiene telegram_id pero es diferente al actual
        if ($guardian->telegram_id && $guardian->telegram_id !== $session->chat_id) {
            $this->messages->guardianAlreadyRegistered($session->chat_id);
            return;
        }

        // Vincular o actualizar el telegram_id
        $guardian->update(['telegram_id' => $session->chat_id]);
        $session->update(['step' => SessionStep::COMPLETED]);

        $this->messages->registrationSuccess($session->chat_id, $guardian);
    }

    private function completed(TelegramSession $session, $update): void
    {
        if ($update->getCallbackQuery()) {
            $data = $update->getCallbackQuery()->getData();
            
            if ($data === 'add_another') {
                $session->update(['step' => SessionStep::ENTER_CURP]);
                $this->messages->requestCurp($session->chat_id);
                return;
            }
        }

        $this->messages->completedMenu($session->chat_id);
    }
}