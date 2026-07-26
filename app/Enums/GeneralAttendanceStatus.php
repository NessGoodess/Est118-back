<?php

namespace App\Enums;

enum GeneralAttendanceStatus: string
{
    case Present = 'present';
    case Late = 'late';
    case Absent = 'absent';
    case Excused = 'excused';
    case Pending = 'pending';
}
