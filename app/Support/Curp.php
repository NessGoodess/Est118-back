<?php

namespace App\Support;

/**
 * Derives birth date and sex from a CURP. Age is not stored.
 */
class Curp
{
    public const PATTERN = '/^[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[0-9A-Z][0-9]$/';

    public static function normalize(?string $curp): string
    {
        return strtoupper(trim((string) $curp));
    }

    /**
     * @return array{birth_date: string, gender: 'M'|'F', birth_year: int}|null
     */
    public static function derive(?string $curp): ?array
    {
        $value = self::normalize($curp);
        if ($value === '' || preg_match(self::PATTERN, $value) !== 1) {
            return null;
        }

        $yearNum = (int) substr($value, 4, 2);
        $month = (int) substr($value, 6, 2);
        $day = (int) substr($value, 8, 2);
        $fullYear = self::fullYear($yearNum);
        if (! checkdate($month, $day, $fullYear)) {
            return null;
        }

        return [
            'birth_date' => sprintf('%04d-%02d-%02d', $fullYear, $month, $day),
            'gender' => $value[10] === 'H' ? 'M' : 'F',
            'birth_year' => $fullYear,
        ];
    }

    private static function fullYear(int $yearNum): int
    {
        $currentYear = (int) now()->year;
        $year2000 = 2000 + $yearNum;
        $year1900 = 1900 + $yearNum;
        $age2000 = $currentYear - $year2000;
        $age1900 = $currentYear - $year1900;

        if ($age2000 >= 4 && $age2000 <= 100) {
            return $year2000;
        }
        if ($age1900 >= 4 && $age1900 <= 100) {
            return $year1900;
        }

        return $yearNum > ($currentYear % 100) ? $year1900 : $year2000;
    }
}
