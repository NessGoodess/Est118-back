<?php

namespace App\Jobs;

use App\Enums\PrintJobStatus;
use App\Models\PrintJob;
use App\Services\Print\StudentCardRenderService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RenderStudentCardJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 120;

    public function __construct(
        public int $printJobId
    ) {}

    public function handle(StudentCardRenderService $renderer): void
    {
        $job = PrintJob::query()->find($this->printJobId);
        if (! $job) {
            return;
        }

        if (in_array($job->status, [PrintJobStatus::Cancelled, PrintJobStatus::Completed], true)) {
            return;
        }

        try {
            $renderer->render($job);
        } catch (\Throwable $e) {
            Log::error('RenderStudentCardJob failed', [
                'print_job_id' => $this->printJobId,
                'error' => $e->getMessage(),
            ]);

            $job->update([
                'status' => PrintJobStatus::Failed,
                'last_error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}
