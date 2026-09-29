<?php

namespace App\Enums;

enum PrintBatchStrategy: string
{
    case FrontsThenBacks = 'fronts_then_backs';
    case PerCard = 'per_card';
    case BackOnly = 'back_only';
}
