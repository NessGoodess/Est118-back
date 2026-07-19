<?php

namespace App\Services;

use App\Models\NfcReaderSlot;
use Illuminate\Support\Collection;

class NfcReaderSlotService
{
    private function isSimulatedPcsc(?string $name): bool
    {
        return $name !== null && str_starts_with($name, 'SIM-READER-');
    }

    /**
     * ACR readers expose PICC (NFC) and SAM (secure module); only PICC reads cards.
     */
    private function isNfcCapableReader(string $name): bool
    {
        $upper = strtoupper($name);

        if (str_contains($upper, ' SAM ') || str_ends_with($upper, ' SAM 0')) {
            return false;
        }

        return true;
    }

    /**
     * @param  array<int, string|array<string, mixed>>  $connectedReaders
     * @return Collection<int, string>
     */
    private function extractNfcPcscNames(array $connectedReaders): Collection
    {
        return collect($connectedReaders)
            ->map(function ($item) {
                if (is_array($item)) {
                    return $item['pcsc_name'] ?? $item['name'] ?? null;
                }

                return $item;
            })
            ->filter()
            ->filter(fn (string $name) => $this->isNfcCapableReader($name))
            ->unique()
            ->values();
    }

    /** Remove virtual reader names left from lab simulation. */
    public function clearSimulatedBindings(): void
    {
        NfcReaderSlot::query()
            ->where('pcsc_name', 'like', 'SIM-READER-%')
            ->update(['pcsc_name' => null]);
    }

    /**
     * Assign unmapped physical readers to the first free active slot (pairing).
     *
     * @param  array<int, string|array<string, mixed>>  $connectedReaders
     */
    public function autoPairConnectedReaders(array $connectedReaders): void
    {
        $this->clearSimulatedBindings();

        $pcscNames = $this->extractNfcPcscNames($connectedReaders);
        if ($pcscNames->isEmpty()) {
            return;
        }

        $slots = NfcReaderSlot::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        $knownPcsc = $slots
            ->pluck('pcsc_name')
            ->filter(fn (?string $name) => $name && ! $this->isSimulatedPcsc($name))
            ->values();

        foreach ($pcscNames as $pcscName) {
            if ($knownPcsc->contains($pcscName)) {
                NfcReaderSlot::query()
                    ->where('pcsc_name', $pcscName)
                    ->update(['last_seen_at' => now()]);

                continue;
            }

            $freeSlot = $slots->first(
                fn (NfcReaderSlot $slot) => $slot->pcsc_name === null || $this->isSimulatedPcsc($slot->pcsc_name)
            );

            if (! $freeSlot) {
                break;
            }

            $freeSlot->update([
                'pcsc_name' => $pcscName,
                'last_seen_at' => now(),
            ]);
            $knownPcsc->push($pcscName);
        }
    }

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

        if (! $pcscName || $this->isSimulatedPcsc($pcscName) || ! $this->isNfcCapableReader($pcscName)) {
            return null;
        }

        return NfcReaderSlot::query()
            ->where('pcsc_name', $pcscName)
            ->where('is_active', true)
            ->first();
    }

    public function enrichPayload(array $data, array $payload): array
    {
        $slot = $this->resolveFromEvent($data);

        $readerPcsc = $data['reader_pcsc'] ?? $data['reader'] ?? $payload['reader'] ?? null;
        if ($readerPcsc && $this->isNfcCapableReader($readerPcsc)) {
            $payload['reader_pcsc'] = $readerPcsc;
        }

        if (! $slot) {
            return $payload;
        }

        if (($slot->pcsc_name === null || $this->isSimulatedPcsc($slot->pcsc_name))
            && ! empty($payload['reader_pcsc'])) {
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
     * One row per active slot — used by the attendance UI panels.
     *
     * @param  array<int, string|array<string, mixed>>  $connectedReaders
     * @return array<int, array<string, mixed>>
     */
    public function buildReaderStatusList(array $connectedReaders): array
    {
        $this->autoPairConnectedReaders($connectedReaders);

        $pcscNames = $this->extractNfcPcscNames($connectedReaders);

        $slots = NfcReaderSlot::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();

        return $slots->map(function (NfcReaderSlot $slot) use ($pcscNames) {
            $connected = $slot->pcsc_name !== null
                && ! $this->isSimulatedPcsc($slot->pcsc_name)
                && $pcscNames->contains($slot->pcsc_name);

            return [
                'pcsc_name' => $slot->pcsc_name,
                'connected' => $connected,
                'slot_id' => $slot->id,
                'slot_code' => $slot->code,
                'label' => $slot->label,
                'audience' => $slot->audience->value,
                'direction' => $slot->direction->value,
                'armed' => $slot->is_armed,
            ];
        })->values()->all();
    }

    public function listActiveSlots(): Collection
    {
        return NfcReaderSlot::query()
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->get();
    }
}
