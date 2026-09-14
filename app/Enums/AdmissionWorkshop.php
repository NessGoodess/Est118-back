<?php

namespace App\Enums;

enum AdmissionWorkshop: string
{
    case ApparelAndTextile = 'Confección del vestido e industria textil';
    case MachinesAndControl = 'Máquinas, herramientas y sistemas de control';
    case IndustrialDesign = 'Diseño Industrial';
    case Informatics = 'Informática';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    public function code(): string
    {
        return match ($this) {
            self::Informatics => 'INFORMATICA',
            self::IndustrialDesign => 'DISENO',
            self::ApparelAndTextile => 'CONFECCION',
            self::MachinesAndControl => 'MAQUINAS',
        };
    }

    public static function fromName(string $name): ?self
    {
        $needle = self::normalize($name);
        foreach (self::cases() as $case) {
            if (self::normalize($case->value) === $needle) {
                return $case;
            }
        }

        return null;
    }

    public static function normalize(string $value): string
    {
        $folded = mb_strtolower(trim($value), 'UTF-8');
        $withoutAccents = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $folded) ?: $folded;

        return preg_replace('/[^a-z0-9]+/', '', $withoutAccents) ?? $withoutAccents;
    }
}
