<?php

namespace App\Services\Telegram;

use App\Enums\SessionStep;
use App\Models\Guardian;
use App\Models\TelegramSession;

class TelegramFlowService
{
    public function __construct(
        private TelegramMessageService $messages,
        private TelegramDomainService $domain
    ) {}

    public function handle($update): void
    {
        $chatId = $this->getChatId($update);

        $session = TelegramSession::firstOrCreate(
            ['chat_id' => $chatId],
            ['step' => SessionStep::START]
        );

        match ($session->step) {
            SessionStep::START        => $this->start($session, $update),
            SessionStep::SELECT_GRADE => $this->selectGrade($session, $update),
            SessionStep::SELECT_GROUP => $this->selectGroup($session, $update),
            SessionStep::ENTER_CURP   => $this->enterCurp($session, $update),
            SessionStep::CONFIRM      => $this->confirmGuardian($session, $update),
            SessionStep::COMPLETED    => $this->completed($chatId),
        };
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
            $session->update(['step' => SessionStep::SELECT_GRADE]);
            $this->messages->sendGrades($session->chat_id);
            return;
        }

        $this->messages->welcome($session->chat_id);
    }

    private function selectGrade(TelegramSession $session, $update): void
    {
        if (!$update->getCallbackQuery()) {
            $this->messages->requireButton($session->chat_id);
            return;
        }

        $grade = $update->getCallbackQuery()->getData();

        if (!$this->domain->isValidGrade($grade)) {
            $this->messages->invalidOption($session->chat_id);
            return;
        }

        $session->update([
            'step'  => SessionStep::SELECT_GROUP,
            'grade' => $grade
        ]);

        $this->messages->gradeSelected($session->chat_id, $grade);
        $this->messages->sendGroups($session->chat_id);
    }

    private function selectGroup(TelegramSession $session, $update): void
    {
        if (!$update->getCallbackQuery()) {
            $this->messages->requireButton($session->chat_id);
            return;
        }

        $group = $update->getCallbackQuery()->getData();

        if (!$this->domain->isValidGroup($group)) {
            $this->messages->invalidOption($session->chat_id);
            return;
        }

        $session->update([
            'step'  => SessionStep::ENTER_CURP,
            'group' => $group
        ]);

        $this->messages->groupSelected($session->chat_id, $group);
        $this->messages->requestCurp($session->chat_id);
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

        $student = $this->domain->findStudent(
            $session->grade,
            $session->group,
            $curp
        );

        if (!$student) {
            $this->messages->studentNotFound($session->chat_id);
            return;
        }
        /*if ($this->domain->studentAlreadyLinked($student)) {
            $this->messages->studentAlreadyRegistered($chatId, $student, $session);
            return;
        }*/

        $relatedStudents = $this->domain->getOtherStudentsForTutor($student);

        $session->update([
            'step' => SessionStep::CONFIRM,
            'curp' => $curp
        ]);

        $this->messages->showGuardians(
            $session->chat_id,
            $student,
            $session,
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

        if (!$guardian || $guardian->telegram_id) {
            $this->messages->guardianAlreadyRegistered($session->chat_id);
            return;
        }

        $guardian->update(['telegram_id' => $session->chat_id]);
        $session->update(['step' => SessionStep::COMPLETED]);

        $this->messages->registrationSuccess($session->chat_id);
    }

    private function completed(int $chatId): void
    {
        $this->messages->completedMenu($chatId);
    }
}