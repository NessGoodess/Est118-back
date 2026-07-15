<?php

namespace App\Services;

use App\Models\NfcReaderSlot;
use Illuminate\Support\Collection;

class NfcReaderSlotService
{

/**
 * Resolve the NFC reader slot from the event data.
 * @param array $data Event data.
 * @return NfcReaderSlot|null NFC reader slot.
 */
    public function resolveFromEvent(array $data): ?NfcReaderSlot
    {
        if (! empty($data['reader_slot_code'])) {
            $slot = NfcReaderSlot::query()
                ->where('code', $data['reader_slot_code'])
                ->where('is_active', true)
                ->first();

            if ($slot) {
                return $slot;
            }
        }

        $pcscName = $data['reader_pcsc'] ?? $data['reader'] ?? null;

        if (! $pcscName) {
            return null;
        }

        return NfcReaderSlot::query()
            ->where('pcsc_name', $pcscName)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Enrich the payload with the data of the NFC reader slot.
     * @param array $data Event data.
     * @param array $payload Payload to enrich.
     * @return array Enriched payload.
     */
    public function enrichPayload(array $data, array $payload): array
    {
        $slot = $this->resolveFromEvent($data);

        $payload['reader_pcsc'] = $data['reader_pcsc'] ?? $data['reader'] ?? $payload['reader'] ?? null;

        if (! $slot) {
            return $payload;
        }

        if ($slot->pcsc_name === null && ! empty($payload['reader_pcsc'])) {
            $slot->update([
                'pcsc_name' => $payload['reader_pcsc'],
                'last_seen_at' => now(),
            ]);
        } elseif ($slot->pcsc_name !== null) {
            $slot->update(['last_seen_at' => now()]);
        }

        return $payload + [
            'reader_slot_id' => $slot->id,
            'reader_slot_code' => $slot->code,
            'reader_label' => $slot->label,
            'reader_audience' => $slot->audience->value,
            'reader_direction' => $slot->direction->value,
            'reader_armed' => $slot->is_armed,
        ];
    }

    /**
     * Build the list of connected NFC readers.
     *
     * @param  array<int, string|array<string, mixed>>  $connectedReaders
     * @return array<int, array<string, mixed>>
     */
    public function buildReaderStatusList(array $connectedReaders): array
    {
        $pcscNames = collect($connectedReaders)->map(function ($item) {
            if (is_array($item)) {
                return $item['pcsc_name'] ?? $item['name'] ?? null;
            }

            return $item;
        })->filter()->values();

        $slots = NfcReaderSlot::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $byPcsc = $slots->filter(fn (NfcReaderSlot $s) => $s->pcsc_name !== null)
            ->keyBy('pcsc_name');

        $result = [];

        foreach ($pcscNames as $pcscName) {
            $slot = $byPcsc->get($pcscName);

            $result[] = [
                'pcsc_name' => $pcscName,
                'connected' => true,
                'slot_id' => $slot?->id,
                'slot_code' => $slot?->code,
                'label' => $slot?->label ?? $pcscName,
                'audience' => $slot?->audience?->value,
                'direction' => $slot?->direction?->value,
                'armed' => $slot?->is_armed ?? true,
            ];
        }

        foreach ($slots as $slot) {
            if ($slot->pcsc_name && $pcscNames->contains($slot->pcsc_name)) {
                continue;
            }

            $result[] = [
                'pcsc_name' => $slot->pcsc_name,
                'connected' => $slot->pcsc_name ? $pcscNames->contains($slot->pcsc_name) : false,
                'slot_id' => $slot->id,
                'slot_code' => $slot->code,
                'label' => $slot->label,
                'audience' => $slot->audience->value,
                'direction' => $slot->direction->value,
                'armed' => $slot->is_armed,
            ];
        }

        return $result;
    }

    /**
     * List the active NFC reader slots.
     * @return Collection Active NFC reader slots.
     */
    public function listActiveSlots(): Collection
    {
        return NfcReaderSlot::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }
}
