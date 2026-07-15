<?php

namespace App\Enums;

enum NfcReaderDirection: string
{
    case ENTRY = 'entry';
    case EXIT = 'exit';
    case BOTH = 'both';
}
