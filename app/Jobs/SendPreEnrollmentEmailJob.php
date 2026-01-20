<?php

namespace App\Jobs;

use App\Mail\PreEnrollmentConfirmedMail;
use App\Models\PreEnrollment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class SendPreEnrollmentEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Max attempts before marking as failed.
     */
    public int $tries = 3;

    /**
     * Delay between retries.
     */
    public int $backoff = 60;

    /**
     * Max execution time in seconds.
     */
    public int $timeout = 120;

    /**
     * Cre
     */
    public function __construct(
        public PreEnrollment $preEnrollment,
        public string $pdfPath
    ) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        Log::info('Enviando email de confirmación de preinscripción', [
            'folio' => $this->preEnrollment->folio,
            'email' => $this->preEnrollment->contact_email,
        ]);

        Mail::to($this->preEnrollment->contact_email)
            ->send(new PreEnrollmentConfirmedMail($this->preEnrollment, $this->pdfPath));

        Log::info('Email de preinscripción enviado exitosamente', [
            'folio' => $this->preEnrollment->folio,
        ]);
    }

    /**
     * Handle a job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error('Error enviando email de preinscripción', [
            'folio' => $this->preEnrollment->folio,
            'email' => $this->preEnrollment->contact_email,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);
    }
}
