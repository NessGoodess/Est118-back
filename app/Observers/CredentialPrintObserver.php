<?php

namespace App\Observers;

use App\Models\CredentialPrint;
use App\Services\Print\PrintStatusBroadcaster;

class CredentialPrintObserver
{
    public function __construct(private readonly PrintStatusBroadcaster $broadcasts) {}

    public function saved(CredentialPrint $card): void
    {
        $this->broadcasts->card($card);
    }
}
