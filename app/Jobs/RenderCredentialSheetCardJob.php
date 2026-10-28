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
        public ?string $templateKey = null,
        public ?int $gradeLevelId = null,
        public ?string $designUuid = null,
    ) {}

    public function handle(CredentialSheetService $sheets): void
    {
        try {
            $sheets->renderCard(
                $this->sheetUuid,
                $this->holderId,
                $this->templateKey,
                $this->gradeLevelId,
                $this->designUuid,
            );
        } catch (\Throwable $e) {
            Log::error('RenderCredentialSheetCardJob failed', [
                'sheet_uuid' => $this->sheetUuid,
                'holder_id' => $this->holderId,
                'template_key' => $this->templateKey,
                'design_uuid' => $this->designUuid,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
