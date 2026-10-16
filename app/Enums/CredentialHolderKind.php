<?php

namespace App\Enums;

/**
 * Who a printed credential belongs to. Matches CardDesign.audience.
 * NFC/attendance stays student-only; this only drives printing.
 */
enum CredentialHolderKind: string
{
    case Students = 'students';
    case Teachers = 'teachers';
    case Staff = 'staff';
    case Guardians = 'guardians';

    public function label(): string
    {
        return match ($this) {
            self::Students => 'Alumno',
            self::Teachers => 'Maestro',
            self::Staff => 'Personal',
            self::Guardians => 'Tutor',
        };
    }

    /**
     * @return class-string<\App\Models\Teacher|\App\Models\Staff|\App\Models\Guardian>|null
     */
    public function personModel(): ?string
    {
        return match ($this) {
            self::Teachers => \App\Models\Teacher::class,
            self::Staff => \App\Models\Staff::class,
            self::Guardians => \App\Models\Guardian::class,
            self::Students => null,
        };
    }

    public function photoKind(): ?PersonPhotoKind
    {
        return match ($this) {
            self::Teachers => PersonPhotoKind::Teachers,
            self::Staff => PersonPhotoKind::Staff,
            self::Guardians => PersonPhotoKind::Guardians,
            self::Students => null,
        };
    }
}
