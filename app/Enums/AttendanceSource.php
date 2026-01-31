<?php

namespace App\Enums;

enum AttendanceSource: string
{
    case NFC = 'nfc';
    case MANUAL = 'manual';
    case IMPORT = 'import';
}
