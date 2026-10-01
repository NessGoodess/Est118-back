<?php

namespace App\Observers;

use App\Models\PrintJob;
use App\Services\Print\PrintStatusBroadcaster;

class PrintJobObserver
{
    public function __construct(private readonly PrintStatusBroadcaster $broadcasts) {}

    public function saved(PrintJob $job): void
    {
        $this->broadcasts->job($job);
    }
}
