<?php

namespace App\Enums;

enum WorkshopEnrollmentStatus: string
{
    case Assigned = 'assigned';
    case Waitlisted = 'waitlisted';
    case Cancelled = 'cancelled';
}
