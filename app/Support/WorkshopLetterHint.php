<?php

namespace App\Support;

class WorkshopLetterHint
{
    public static function expectedCode(string $groupLetter): ?string
    {
        return match (strtoupper(trim($groupLetter))) {
            'G' => 'INFORMATICA',
            'H' => 'DISENO',
            default => null,
        };
    }
}
