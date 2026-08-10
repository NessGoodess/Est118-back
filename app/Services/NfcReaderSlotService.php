<?php

namespace App\Services;

use App\Models\NfcReaderSlot;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class NfcReaderSlotService
{
    public const PAIRING_CACHE_KEY = 'nfc_pairing_session';

    public const STATUS_CACHE_KEY = 'nfc_reader_status';

    /**
     * ACR readers expose PICC (NFC) and SAM (secure module); only PICC reads cards.
     * ACR1252 names look like "... Dual Reader SAM] 01 00" (SAM before ']'), not always " SAM ".
     */
    private function isNfcCapableReader(string $name): bool
    {
        return preg_match('/\bSAM\b/i', $name) !== 1;
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

    /**
     * Refresh last_seen_at for slots whose PC/SC is currently reported online.
     * Does not assign unbound readers (operators pair explicitly).
     *
     * @param  array<int, string|array<string, mixed>>  $connectedReaders
     */
    private function touchLastSeenForConnected(array $connectedReaders): void
    {
        $pcscNames = $this->extractNfcPcscNames($connectedReaders);
        foreach ($pcscNames as $pcscName) {
            NfcReaderSlot::query()
                ->where('pcsc_name', $pcscName)
                ->update(['last_seen_at' => now()]);
        }
    }

    /**
     * Resolve panel only by physical PC/SC name bound in Laravel.
     * Pi-local reader_slot_code is ignored (can disagree after USB reorder).
     */
    public function resolveFromEvent(array $data): ?NfcReaderSlot
    {
        $pcscName = $data['reader_pcsc'] ?? $data['reader'] ?? null;

        if (! $pcscName || ! $this->isNfcCapableReader($pcscName)) {
            return null;
        }

        return NfcReaderSlot::query()
            ->where('pcsc_name', $pcscName)
            ->where('is_active', true)
            ->first();
    }

    public function enrichPayload(array $data, array $payload, bool $allowPairing = false): array
    {
        $readerPcsc = $data['reader_pcsc'] ?? $data['reader'] ?? $payload['reader'] ?? null;
        if ($readerPcsc && $this->isNfcCapableReader($readerPcsc)) {
            $payload['reader_pcsc'] = $readerPcsc;
        }

        $paired = null;
        if ($allowPairing) {
            // Guided pairing: bind this physical reader to the chosen slot on tap.
            $paired = $this->tryCompletePairing($payload['reader_pcsc'] ?? null);
        }

        $slot = $paired ?? $this->resolveFromEvent($data + [
            'reader_pcsc' => $payload['reader_pcsc'] ?? null,
        ]);

        if (! $slot) {
            return $payload;
        }

        if ($slot->pcsc_name !== null) {
            $slot->update(['last_seen_at' => now()]);
        }

        $enriched = $payload + [
            'reader_slot_id' => $slot->id,
            'reader_slot_code' => $slot->code,
            'reader_label' => $slot->label,
            'reader_audience' => $slot->audience->value,
            'reader_direction' => $slot->direction->value,
            'reader_armed' => $slot->is_armed,
        ];

        if ($paired) {
            $enriched['pairing_completed'] = true;
            $enriched['message'] = 'Lector emparejado: '.$slot->label;
        }

        return $enriched;
    }

    /**
     * One row per active slot — used by the attendance UI panels.
     *
     * @param  array<int, string|array<string, mixed>>  $connectedReaders
     * @return array<int, array<string, mixed>>
     */
    public function buildReaderStatusList(array $connectedReaders): array
    {
        // Refresh last_seen for known bindings only (no silent assign).
        $this->touchLastSeenForConnected($connectedReaders);

        $pcscNames = $this->extractNfcPcscNames($connectedReaders);

        return $this->mapSlotsToStatusRows($pcscNames);
    }

    /**
     * @param  Collection<int, string>  $pcscNames
     * @return array<int, array<string, mixed>>
     */
    private function mapSlotsToStatusRows(Collection $pcscNames): array
    {
        return $this->listActiveSlots()->map(function (NfcReaderSlot $slot) use ($pcscNames) {
            $connected = $slot->pcsc_name !== null
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

    public function listAllSlots(): Collection
    {
        return NfcReaderSlot::query()
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * @param  array<int, string|array<string, mixed>>  $connectedReaders
     * @return list<string>
     */
    public function listConnectedPcscNames(array $connectedReaders): array
    {
        return $this->extractNfcPcscNames($connectedReaders)->all();
    }

    /**
     * @param  list<string>  $connectedPcsc
     * @return list<string>
     */
    public function listUnboundPcscNames(array $connectedPcsc): array
    {
        $bound = NfcReaderSlot::query()
            ->whereNotNull('pcsc_name')
            ->pluck('pcsc_name')
            ->filter()
            ->all();

        return array_values(array_diff($connectedPcsc, $bound));
    }

    /**
     * Assign a physical PC/SC name to a slot, clearing the same name from others.
     */
    public function assignPcsc(NfcReaderSlot $slot, ?string $pcscName): NfcReaderSlot
    {
        if ($pcscName !== null && $pcscName !== '') {
            if (! $this->isNfcCapableReader($pcscName) || strcasecmp($pcscName, 'NFC Reader') === 0) {
                throw new \InvalidArgumentException('Nombre PC/SC no válido para NFC.');
            }

            NfcReaderSlot::query()
                ->where('pcsc_name', $pcscName)
                ->where('id', '!=', $slot->id)
                ->update(['pcsc_name' => null]);

            $slot->update([
                'pcsc_name' => $pcscName,
                'last_seen_at' => now(),
            ]);
        } else {
            $slot->update(['pcsc_name' => null]);
        }

        $this->forgetPairingSession();

        return $slot->fresh();
    }

    public function startPairing(NfcReaderSlot $slot, bool $clearExisting = true): array
    {
        if ($clearExisting) {
            $slot->update(['pcsc_name' => null]);
        }

        $session = [
            'slot_id' => $slot->id,
            'slot_code' => $slot->code,
            'slot_label' => $slot->label,
            'started_at' => now()->toIso8601String(),
            'expires_at' => now()->addMinutes(2)->toIso8601String(),
        ];

        Cache::put(self::PAIRING_CACHE_KEY, $session, now()->addMinutes(2));

        return $session;
    }

    public function getPairingSession(): ?array
    {
        $session = Cache::get(self::PAIRING_CACHE_KEY);
        if (! is_array($session) || empty($session['slot_id'])) {
            return null;
        }

        if (! empty($session['expires_at']) && now()->greaterThan($session['expires_at'])) {
            $this->forgetPairingSession();

            return null;
        }

        return $session;
    }

    public function forgetPairingSession(): void
    {
        Cache::forget(self::PAIRING_CACHE_KEY);
    }

    /**
     * If a guided pairing session is open, bind the given PC/SC to the target slot.
     */
    public function tryCompletePairing(?string $pcscName): ?NfcReaderSlot
    {
        if (
            ! $pcscName
            || ! $this->isNfcCapableReader($pcscName)
            || strcasecmp($pcscName, 'NFC Reader') === 0
        ) {
            return null;
        }

        $session = $this->getPairingSession();
        if (! $session) {
            return null;
        }

        $slot = NfcReaderSlot::query()->find($session['slot_id']);
        if (! $slot) {
            $this->forgetPairingSession();

            return null;
        }

        return $this->assignPcsc($slot, $pcscName);
    }

    /**
     * Operator-facing config payload for the pairing UI.
     * Only operational panels (active) — entry/exit are not used; horario decides.
     *
     * @return array<string, mixed>
     */
    public function buildConfigPayload(): array
    {
        $status = Cache::get(self::STATUS_CACHE_KEY, [
            'event' => 'reader_status_changed',
            'connected' => false,
            'ready' => false,
            'readers' => [],
            'connected_pcsc' => [],
            'timestamp' => null,
        ]);

        $connectedPcsc = $status['connected_pcsc'] ?? [];
        if (! is_array($connectedPcsc)) {
            $connectedPcsc = [];
        }

        // Legacy / partial cache: recover names from raw string readers if needed.
        if ($connectedPcsc === [] && is_array($status['readers'] ?? null)) {
            $connectedPcsc = $this->listConnectedPcscNames($status['readers']);
        }

        $connectedPcsc = array_values(array_unique(array_filter($connectedPcsc)));

        return [
            'slots' => $this->listAllSlots(),
            'status' => $status,
            'connected_pcsc' => $connectedPcsc,
            'unbound_pcsc' => $this->listUnboundPcscNames($connectedPcsc),
            'pairing' => $this->getPairingSession(),
        ];
    }

    /**
     * Rebuild panel connection flags in cache after a manual PC/SC assign.
     * Keeps UI in sync without waiting for the next Pi heartbeat.
     *
     * @return array<string, mixed>
     */
    public function rebuildCachedReaderStatus(): array
    {
        $status = Cache::get(self::STATUS_CACHE_KEY, [
            'event' => 'reader_status_changed',
            'connected' => false,
            'ready' => false,
            'readers' => [],
            'connected_pcsc' => [],
            'timestamp' => null,
        ]);

        $connectedPcsc = $status['connected_pcsc'] ?? [];
        if (! is_array($connectedPcsc)) {
            $connectedPcsc = [];
        }

        if ($connectedPcsc === [] && is_array($status['readers'] ?? null)) {
            $connectedPcsc = $this->listConnectedPcscNames($status['readers']);
        }

        $connectedPcsc = array_values(array_unique(array_filter($connectedPcsc)));
        $readers = $this->mapSlotsToStatusRows(collect($connectedPcsc));

        $anyConnected = collect($readers)->contains(fn (array $r) => $r['connected'] === true);

        $status = array_merge($status, [
            'event' => 'reader_status_changed',
            'connected' => $anyConnected || (bool) ($status['connected'] ?? false),
            'ready' => (bool) ($status['ready'] ?? $anyConnected),
            'readers' => $readers,
            'connected_pcsc' => $connectedPcsc,
            'unbound_pcsc' => $this->listUnboundPcscNames($connectedPcsc),
            'pairing' => $this->getPairingSession(),
            'timestamp' => now()->toIso8601String(),
        ]);

        Cache::put(self::STATUS_CACHE_KEY, $status, now()->addHours(2));

        return $status;
    }
}
