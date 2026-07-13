<?php

namespace App\Enums;

enum ReEnrollmentEventAction: string
{
    case OPENED = 'opened';
    case CLOSED = 'closed';
    case STEP_ADVANCED = 'step_advanced';
    case PROMOTION_DRY_RUN = 'promotion_dry_run';
    case PROMOTION_EXECUTED = 'promotion_executed';
    case FINALIZED = 'finalized';
    case ACADEMIC_YEAR_ACTIVATED = 'academic_year_activated';
}
