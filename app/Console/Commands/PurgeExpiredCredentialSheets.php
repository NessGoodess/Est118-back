<?php

namespace App\Console\Commands;

use App\Services\Print\CredentialSheetService;
use Illuminate\Console\Command;

class PurgeExpiredCredentialSheets extends Command
{
    protected $signature = 'credential-sheets:purge-expired';

    protected $description = 'Delete credential sheet PNG folders older than one hour';

    public function handle(CredentialSheetService $sheets): int
    {
        $removed = $sheets->purgeExpired();
        $this->info("Purged {$removed} expired sheet job(s).");

        return self::SUCCESS;
    }
}
