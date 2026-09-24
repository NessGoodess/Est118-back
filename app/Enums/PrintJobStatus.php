<?php

namespace App\Enums;

enum PrintJobStatus: string
{
    case Pending = 'pending';
    case Ready = 'ready';
    case Claimed = 'claimed';
    case Printing = 'printing';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
