<?php

namespace App\Enums;

enum ReEnrollmentProcessStep: string
{
    case CONFIGURATION = 'configuration';
    case VALIDATION = 'validation';
    case PROMOTION = 'promotion';
    case GROUPS = 'groups';
    case COMPLETED = 'completed';
}
