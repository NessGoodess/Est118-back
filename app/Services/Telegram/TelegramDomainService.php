<?php

namespace App\Services\Telegram;

use App\Models\Profile;

class TelegramDomainService
{
    public function isValidGrade(string $grade): bool
    {
        return in_array($grade, ['1°', '2°', '3°']);
    }

    public function isValidGroup(string $group): bool
    {
        return in_array($group, range('A', 'H'));
    }

    public function isValidCurp(string $curp): bool
    {
        return preg_match(
            '/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/',
            $curp
        ) === 1;
    }

    public function findStudent(
        string $grade,
        string $group,
        string $curp
    ): ?Profile {
        return Profile::query()
            ->where('national_id', $curp)
            ->whereHas(
                'student.enrollments.classGroup.gradeLevel',
                fn($q) =>
                $q->where('status', 'active')
                    ->where('class_groups.name', $group)
                    ->where('grade_levels.name', $grade)
            )
            ->with('student.guardians.profile')
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
