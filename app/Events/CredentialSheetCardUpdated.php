<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Progressive sheet-card render updates (cache jobs, no DB).
 * Realtime shortcut — clients still poll if Reverb is down.
 */
class CredentialSheetCardUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<string, mixed>  $card
     * @param  array{total: int, ready: int, failed: int, rendering: int}  $summary
     */
    public function __construct(
        public string $uuid,
        public array $card,
        public array $summary,
        public string $status,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('credential-sheets.'.$this->uuid)];
    }

    public function broadcastAs(): string
    {
        return 'credential-sheet.card-updated';
    }

    /**
     * @return array{uuid: string, card: array<string, mixed>, summary: array<string, int>, status: string}
     */
    public function broadcastWith(): array
    {
        return [
            'uuid' => $this->uuid,
            'card' => $this->card,
            'summary' => $this->summary,
            'status' => $this->status,
        ];
    }
}
