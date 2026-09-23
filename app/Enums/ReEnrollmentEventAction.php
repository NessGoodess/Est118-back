<?php

namespace App\Enums;

enum ReEnrollmentEventAction: string
{
    case OPENED = 'opened';
    case CLOSED = 'closed';
    case STEP_ADVANCED = 'step_advanced';
    case BULK_VALIDATED = 'bulk_validated';
    case BULK_DECIDED = 'bulk_decided';
    case APPLICATIONS_SYNCED = 'applications_synced';
    case PROMOTION_DRY_RUN = 'promotion_dry_run';
    case PROMOTION_EXECUTED = 'promotion_executed';
    case FINALIZED = 'finalized';
    case ACADEMIC_YEAR_ACTIVATED = 'academic_year_activated';
}
