<?php

namespace App\Jobs;

use App\Events\CredentialReadEvent;
use App\Models\NfcReadEvent;
use App\Services\NfcAttendanceService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessNfcReadJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $backoff = 5;

    public int $timeout = 60;

    public function __construct(
        public int $nfcReadEventId
    ) {}

    public function handle(NfcAttendanceService $attendanceService): void
    {
        $event = NfcReadEvent::find($this->nfcReadEventId);
        if (! $event) {
            return;
        }

        if ($event->status === NfcReadEvent::STATUS_PROCESSED) {
            return;
        }

        $event->update(['status' => NfcReadEvent::STATUS_PROCESSING]);

        try {
            $payload = $attendanceService->processCardInserted($event->request_payload);

            $event->update([
                'status' => NfcReadEvent::STATUS_PROCESSED,
                'result_payload' => $payload,
                'error_message' => null,
                'processed_at' => now(),
            ]);

            broadcast(new CredentialReadEvent($payload));
        } catch (\Throwable $e) {
            Log::error('ProcessNfcReadJob failed', [
                'nfc_read_event_id' => $event->id,
                'client_event_id' => $event->client_event_id,
                'error' => $e->getMessage(),
            ]);

            $event->update([
                'status' => NfcReadEvent::STATUS_FAILED,
                'error_message' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $event = NfcReadEvent::find($this->nfcReadEventId);
        if (! $event) {
            return;
        }

        $event->update([
            'status' => NfcReadEvent::STATUS_FAILED,
            'error_message' => $exception->getMessage(),
        ]);

        broadcast(new CredentialReadEvent([
            'event' => 'error',
            'status' => 'error',
            'message' => 'Error procesando lectura NFC: '.$exception->getMessage(),
            'client_event_id' => $event->client_event_id,
            'timestamp' => now()->toIso8601String(),
        ]));
    }
}
