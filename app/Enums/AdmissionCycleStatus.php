<?php

namespace App\Enums;

enum AdmissionCycleStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case CLOSED = 'closed';
}
