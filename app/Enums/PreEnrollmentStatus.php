<?php

namespace App\Enums;

enum PreEnrollmentStatus: string
{
    case PENDING = "pending";
    case IN_REVIEW = "in_review";
    case APPROVED = "approved";
    case REJECTED = "rejected";
}
