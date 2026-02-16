<?php

namespace App\Services\Telegram;

use App\Enums\SessionStep;
use App\Models\Guardian;
use App\Models\TelegramSession;
use Telegram\Bot\Laravel\Facades\Telegram;
use Illuminate\Support\Facades\Log;

class TelegramFlowService
{
    public function __construct(
        private TelegramMessageService $messages,
        private TelegramDomainService $domain
    ) {}

    public function handle($update): void
    {
        try {
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
            Log::error('Telegram Flow handler Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            if ($chatId) {
                Telegram::sendMessage([
                    'chat_id' => $chatId,
                    'text' => 'Ocurrió un error interno, por favor si le es posible tome una captura y reporte a la institución.',
                ]);
            }
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
        if ($update->getCallbackQuery()) {
            $data = $update->getCallbackQuery()->getData();

            if ($data === 'cancel') {
                $session->update(['step' => SessionStep::START]);
                $this->messages->cancelMenu($session->chat_id);
                return;
            }
        }

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

        if ($data === 'cancel') {
            $session->update(['step' => SessionStep::START]);
            $this->messages->cancelMenu($session->chat_id);
            return;
        }

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

        $existingGuardian = Guardian::where('telegram_id', $session->chat_id)
            ->where('id', '!=', $guardian->id)
            ->first();

        if ($existingGuardian) {
            $this->messages->telegramAccountLinkedToAnother($session->chat_id);
            return;
        }

        if ($guardian->telegram_id && $guardian->telegram_id !== $session->chat_id) {
            $this->messages->guardianAlreadyRegistered($session->chat_id);
            return;
        }

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
