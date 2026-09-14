<?php

namespace App\Enums;

enum WorkshopEnrollmentSource: string
{
    case FirstChoice = 'first_choice';
    case SecondChoice = 'second_choice';
    case Leftover = 'leftover';
    case Inherited = 'inherited';
    case Manual = 'manual';
    case BulkGh = 'bulk_gh';
}
