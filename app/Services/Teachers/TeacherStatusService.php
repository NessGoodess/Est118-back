<?php

namespace App\Services\Teachers;

use App\Enums\TeacherStatus;
use App\Models\Teacher;
use RuntimeException;

class TeacherStatusService
{
    public function setStatus(Teacher $teacher, string $status): Teacher
    {
        $enum = TeacherStatus::tryFrom($status);
        if ($enum === null) {
            throw new RuntimeException('El estado del maestro no es válido.');
        }
        $teacher->status = $enum;
        $teacher->save();

        return $teacher->fresh(['profile.address']);
    }

    public function deactivate(Teacher $teacher): Teacher
    {
        return $this->setStatus($teacher, TeacherStatus::INACTIVE->value);
    }

    public function reactivate(Teacher $teacher): Teacher
    {
        return $this->setStatus($teacher, TeacherStatus::ACTIVE->value);
    }
}
