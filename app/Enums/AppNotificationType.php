<?php

namespace App\Enums;

enum AppNotificationType: string
{
    case PreEnrollmentCreated = 'pre_enrollment.created';

    public function label(): string
    {
        return match ($this) {
            self::PreEnrollmentCreated => 'Nueva preinscripción',
        };
    }
}
