<?php

namespace App\Enums;

enum ReEnrollmentValidationStatus: string
{
    case PENDING = 'pending';
    case IN_REVIEW = 'in_review';
    case VALIDATED = 'validated';
    case REJECTED = 'rejected';
}
