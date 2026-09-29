<?php

namespace App\Enums;

enum CredentialSideStatus: string
{
    case Pending = 'pending';
    case Printed = 'printed';
    case Failed = 'failed';
    case NotApplicable = 'not_applicable';
    case Cancelled = 'cancelled';

    public function isDone(): bool
    {
        return $this === self::Printed || $this === self::NotApplicable;
    }
}
