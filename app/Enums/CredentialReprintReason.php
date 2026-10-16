<?php

namespace App\Enums;

enum CredentialReprintReason: string
{
    case Lost = 'lost';
    case PrintError = 'print_error';
    case PhysicalDamage = 'physical_damage';
    case DataUpdate = 'data_update';
    case WantsNew = 'wants_new';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Lost => 'Extravío',
            self::PrintError => 'Error de impresión',
            self::PhysicalDamage => 'Maltrato físico',
            self::DataUpdate => 'Actualización de datos',
            self::WantsNew => 'Quiere uno nuevo',
            self::Other => 'Otro',
        };
    }

    /** Errores de impresión son de la escuela: se aprueban solos y no se cobran. */
    public function isChargeable(): bool
    {
        return $this !== self::PrintError;
    }
}
