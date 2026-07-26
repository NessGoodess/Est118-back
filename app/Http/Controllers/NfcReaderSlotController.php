<?php

namespace App\Http\Controllers;

use App\Enums\NfcReaderAudience;
use App\Enums\NfcReaderDirection;
use App\Events\CredentialReadEvent;
use App\Models\NfcReaderSlot;
use App\Services\NfcReaderSlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NfcReaderSlotController extends Controller
{
    public function __construct(
        private readonly NfcReaderSlotService $slotService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $slots = $request->boolean('include_inactive')
            ? $this->slotService->listAllSlots()
            : $this->slotService->listActiveSlots();

        return response()->json([
            'success' => true,
            'data' => $slots,
        ]);
    }

    public function config(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->slotService->buildConfigPayload(),
        ]);
    }

    public function update(Request $request, NfcReaderSlot $slot): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:120'],
            'pcsc_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:99'],
            'audience' => ['sometimes', Rule::enum(NfcReaderAudience::class)],
            'direction' => ['sometimes', Rule::enum(NfcReaderDirection::class)],
        ]);

        if (array_key_exists('pcsc_name', $validated)) {
            try {
                $slot = $this->slotService->assignPcsc($slot, $validated['pcsc_name']);
            } catch (\InvalidArgumentException $e) {
                return response()->json([
                    'success' => false,
                    'message' => $e->getMessage(),
                ], 422);
            }
            unset($validated['pcsc_name']);
        }

        if ($validated !== []) {
            $slot->update($validated);
            $slot = $slot->fresh();
        }

        $status = $this->slotService->rebuildCachedReaderStatus();
        broadcast(new CredentialReadEvent($status));

        return response()->json([
            'success' => true,
            'data' => $slot,
            'status' => $status,
        ]);
    }

    public function startPairing(Request $request, NfcReaderSlot $slot): JsonResponse
    {
        $validated = $request->validate([
            'clear_existing' => ['sometimes', 'boolean'],
        ]);

        $session = $this->slotService->startPairing(
            $slot,
            $validated['clear_existing'] ?? true
        );

        $status = $this->slotService->rebuildCachedReaderStatus();
        broadcast(new CredentialReadEvent($status));

        return response()->json([
            'success' => true,
            'message' => 'Modo emparejado activo. Conecta o acerca una credencial en el lector físico.',
            'data' => [
                'pairing' => $session,
                'slot' => $slot->fresh(),
                'status' => $status,
            ],
        ]);
    }

    public function cancelPairing(): JsonResponse
    {
        $this->slotService->forgetPairingSession();
        $status = $this->slotService->rebuildCachedReaderStatus();
        broadcast(new CredentialReadEvent($status));

        return response()->json([
            'success' => true,
            'message' => 'Emparejamiento cancelado.',
            'status' => $status,
        ]);
    }

    public function arm(Request $request, NfcReaderSlot $slot): JsonResponse
    {
        $validated = $request->validate([
            'armed' => ['required', 'boolean'],
        ]);

        $slot->update(['is_armed' => $validated['armed']]);

        $status = $this->slotService->rebuildCachedReaderStatus();
        broadcast(new CredentialReadEvent($status));

        return response()->json([
            'success' => true,
            'message' => $validated['armed'] ? 'Lector activado.' : 'Lector en pausa.',
            'data' => $slot->fresh(),
            'status' => $status,
        ]);
    }

    public function armAll(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'armed' => ['required', 'boolean'],
        ]);

        NfcReaderSlot::query()
            ->where('is_active', true)
            ->update(['is_armed' => $validated['armed']]);

        $status = $this->slotService->rebuildCachedReaderStatus();
        broadcast(new CredentialReadEvent($status));

        return response()->json([
            'success' => true,
            'message' => $validated['armed'] ? 'Todos los lectores activados.' : 'Todos los lectores en pausa.',
            'data' => $this->slotService->listActiveSlots(),
            'status' => $status,
        ]);
    }
}
