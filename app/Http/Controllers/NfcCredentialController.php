<?php

namespace App\Http\Controllers;

use App\Events\CredentialReadEvent;
use App\Jobs\ProcessNfcReadJob;
use App\Models\NfcReadEvent;
use App\Services\NfcReaderSlotService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class NfcCredentialController extends Controller
{
    public function __construct(
        private readonly NfcReaderSlotService $slotService
    ) {}

    /**
     * Handle NFC credential read events from the reader.
     *
     * card_inserted: persisted + queued (idempotent by client_event_id).
     * Other events: processed synchronously.
     */
    public function read(Request $request)
    {
        $data = $request->all();
        $eventType = $data['event'] ?? null;

        $payload = $this->slotService->enrichPayload($data, [
            'reader' => $data['reader'] ?? 'NFC Reader',
            'timestamp' => now()->toIso8601String(),
        ]);

        try {
            if ($eventType === 'card_inserted') {
                return $this->enqueueCardInserted($data, $payload);
            }

            switch ($eventType) {
                case 'card_removed':
                    $payload = $this->handleCardRemoved($payload);
                    break;

                case 'reader_status_changed':
                    $payload = $this->handleReaderStatusChanged($data, $payload);
                    break;

                default:
                    $payload = $this->handleUnknownEvent($payload);
            }

            broadcast(new CredentialReadEvent($payload));

            return response()->json(['status' => 'ok', 'payload' => $payload]);
        } catch (\Throwable $e) {
            Log::error('Error processing NFC credential read', [
                'event' => $eventType,
                'error' => $e->getMessage(),
            ]);

            broadcast(new CredentialReadEvent([
                'event' => 'error',
                'status' => 'error',
                'message' => 'Error interno: '.$e->getMessage(),
                'timestamp' => now()->toIso8601String(),
            ]));

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Idempotent ingest for card reads. ACK fast; ProcessNfcReadJob does the work.
     */
    private function enqueueCardInserted(array $data, array $enrichedBase): \Illuminate\Http\JsonResponse
    {
        $clientEventId = (string) ($data['client_event_id'] ?? Str::uuid());

        $existing = NfcReadEvent::query()
            ->where('client_event_id', $clientEventId)
            ->first();

        if ($existing) {
            if ($existing->status === NfcReadEvent::STATUS_PROCESSED && is_array($existing->result_payload)) {
                return response()->json([
                    'status' => 'ok',
                    'duplicate' => true,
                    'client_event_id' => $clientEventId,
                    'payload' => $existing->result_payload,
                ]);
            }

            if ($existing->status === NfcReadEvent::STATUS_PENDING
                || $existing->status === NfcReadEvent::STATUS_PROCESSING) {
                return response()->json([
                    'status' => 'accepted',
                    'duplicate' => true,
                    'client_event_id' => $clientEventId,
                ], 202);
            }

            // Retry failed events
            $existing->update([
                'status' => NfcReadEvent::STATUS_PENDING,
                'error_message' => null,
                'request_payload' => $data,
            ]);
            $this->dispatchProcessJob($existing->id);

            return response()->json([
                'status' => 'accepted',
                'retried' => true,
                'client_event_id' => $clientEventId,
            ], 202);
        }

        $event = NfcReadEvent::create([
            'client_event_id' => $clientEventId,
            'event_type' => 'card_inserted',
            'credential_id' => $data['credential_id'] ?? null,
            'reader_slot_code' => $enrichedBase['reader_slot_code'] ?? ($data['reader_slot_code'] ?? null),
            'reader_pcsc' => $enrichedBase['reader_pcsc'] ?? ($data['reader'] ?? null),
            'request_payload' => $data,
            'status' => NfcReadEvent::STATUS_PENDING,
        ]);

        $this->dispatchProcessJob($event->id);

        return response()->json([
            'status' => 'accepted',
            'client_event_id' => $clientEventId,
            'nfc_read_event_id' => $event->id,
        ], 202);
    }

    private function dispatchProcessJob(int $nfcReadEventId): void
    {
        $inline = config('queue.default') === 'sync'
            || filter_var(env('NFC_INLINE_PROCESS', false), FILTER_VALIDATE_BOOLEAN);

        if ($inline) {
            ProcessNfcReadJob::dispatchSync($nfcReadEventId);
        } else {
            ProcessNfcReadJob::dispatch($nfcReadEventId);
        }
    }

    private function handleCardRemoved(array $payload): array
    {
        return $payload + [
            'event' => 'card_removed',
            'status' => 'info',
            'message' => 'Tarjeta retirada. Esperando nueva credencial...',
            'student' => null,
        ];
    }

    private function handleReaderStatusChanged(array $data, array $payload): array
    {
        $readers = $this->slotService->buildReaderStatusList($data['readers'] ?? []);
        $anySlotConnected = collect($readers)->contains(fn (array $r) => $r['connected'] === true);

        $status = $payload + [
            'event' => 'reader_status_changed',
            'connected' => $anySlotConnected || (bool) ($data['connected'] ?? false),
            'ready' => (bool) ($data['ready'] ?? false),
            'readers' => $readers,
            'timestamp' => now()->toIso8601String(),
        ];

        Cache::put('nfc_reader_status', $status, now()->addHours(2));

        return $status;
    }

    private function handleUnknownEvent(array $payload): array
    {
        return $payload + [
            'event' => 'unknown',
            'status' => 'warning',
            'message' => 'Evento desconocido.',
        ];
    }

    public function readerStatus()
    {
        $status = Cache::get('nfc_reader_status', [
            'event' => 'reader_status_changed',
            'connected' => false,
            'ready' => false,
            'readers' => [],
            'timestamp' => now()->toIso8601String(),
        ]);

        return response()->json($status);
    }
}
