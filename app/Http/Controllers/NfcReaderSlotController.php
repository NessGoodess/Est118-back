<?php

namespace App\Http\Controllers;

use App\Models\NfcReaderSlot;
use App\Services\NfcReaderSlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NfcReaderSlotController extends Controller
{
    public function __construct(
        private readonly NfcReaderSlotService $slotService
    ) {}

    public function index(): JsonResponse
    {
        $slots = $this->slotService->listActiveSlots();

        return response()->json([
            'success' => true,
            'data' => $slots,
        ]);
    }

    public function update(Request $request, NfcReaderSlot $slot): JsonResponse
    {
        $validated = $request->validate([
            'label' => ['sometimes', 'string', 'max:120'],
            'pcsc_name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'is_active' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:99'],
        ]);

        $slot->update($validated);

        return response()->json([
            'success' => true,
            'data' => $slot->fresh(),
        ]);
    }

    public function arm(Request $request, NfcReaderSlot $slot): JsonResponse
    {
        $validated = $request->validate([
            'armed' => ['required', 'boolean'],
        ]);

        $slot->update(['is_armed' => $validated['armed']]);

        return response()->json([
            'success' => true,
            'message' => $validated['armed'] ? 'Lector activado.' : 'Lector en pausa.',
            'data' => $slot->fresh(),
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

        return response()->json([
            'success' => true,
            'message' => $validated['armed'] ? 'Todos los lectores activados.' : 'Todos los lectores en pausa.',
            'data' => $this->slotService->listActiveSlots(),
        ]);
    }
}
