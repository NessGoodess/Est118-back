<?php

namespace App\Enums;

enum CredentialReprintStatus: string
{
    case Pending = 'pending';
    case AwaitingPayment = 'awaiting_payment';
    case ReadyToPrint = 'ready_to_print';
    case Queued = 'queued';
    case Printed = 'printed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** @return array<int, self> */
    public static function open(): array
    {
        return [self::Pending, self::AwaitingPayment, self::ReadyToPrint, self::Queued];
    }

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }
}
