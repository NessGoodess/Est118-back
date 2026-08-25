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
}
