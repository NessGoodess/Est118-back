<?php

namespace App\Enums;

enum ServiceAbility: string
{
    case NFC_READER = 'service-nfc-reader';

    /**
     * All abilities as strings
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Only service abilities
     */
    public static function serviceOnly(): array
    {
        return self::values();
    }
}
