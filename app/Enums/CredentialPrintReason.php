<?php

namespace App\Enums;

enum CredentialPrintReason: string
{
    case Initial = 'initial';
    case Reprint = 'reprint';
    case Replacement = 'replacement';
    case Migrated = 'migrated';
}
