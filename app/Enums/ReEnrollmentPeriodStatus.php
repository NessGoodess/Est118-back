<?php

namespace App\Enums;

enum ReEnrollmentPeriodStatus: string
{
    case DRAFT = 'draft';
    case OPEN = 'open';
    case CLOSED = 'closed';
    case FINALIZED = 'finalized';
}
