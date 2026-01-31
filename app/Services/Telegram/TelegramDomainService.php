<?php

namespace App\Services\Telegram;

use App\Models\Profile;

class TelegramDomainService
{
    public function isValidCurp(string $curp): bool
    {
        return preg_match(
            '/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/',
            $curp
        ) === 1;
    }

    public function findStudentByCurp(string $curp): ?Profile
    {
        return Profile::query()
            ->where('national_id', $curp)
            ->whereHas(
                'student.enrollments',
                fn($q) => $q->where('status', 'active')
            )
            ->with([
                'student.guardians.profile',
                'student.currentEnrollment.classGroup.gradeLevel'
            ])
            ->first();
    }

    public function getOtherStudentsForTutor(Profile $studentProfile): array
    {
        return $studentProfile->student
            ->guardians
            ->flatMap(fn($guardian) => $guardian->students)
            ->unique('id')
            ->reject(fn($s) => $s->id === $studentProfile->student->id)
            ->values()
            ->all();
    }

    public function studentAlreadyLinked(Profile $studentProfile): bool
    {
        return $studentProfile
            ->student
            ->guardians
            ->contains(fn($g) => !is_null($g->telegram_id));
    }
}