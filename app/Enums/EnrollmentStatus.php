<?php

namespace App\Enums;

enum EnrollmentStatus: string
{
    case PreEnrolled = 'pre_enrolled';
    case Active = 'active';
    case Inactive = 'inactive';
    case Completed = 'completed';
    case Dropped = 'dropped';
}
