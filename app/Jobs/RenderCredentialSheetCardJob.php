<?php

namespace App\Jobs;

use App\Services\Print\CredentialSheetService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RenderCredentialSheetCardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 120;

    public function __construct(
        public string $sheetUuid,
        public int $holderId,
    ) {}

    public function handle(CredentialSheetService $sheets): void
    {
        try {
            $sheets->renderCard($this->sheetUuid, $this->holderId);
        } catch (\Throwable $e) {
            Log::error('RenderCredentialSheetCardJob failed', [
                'sheet_uuid' => $this->sheetUuid,
                'holder_id' => $this->holderId,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
