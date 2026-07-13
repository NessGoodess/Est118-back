<?php

namespace App\Enums;

enum PromotionResult: string
{
    case PROMOTED = 'promoted';
    case RETAINED = 'retained';
    case GRADUATED = 'graduated';
}
