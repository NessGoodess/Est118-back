<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NfcReadEvent extends Model
{
    public const STATUS_PENDING = 'pending';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_PROCESSED = 'processed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'client_event_id',
        'event_type',
        'credential_id',
        'reader_slot_code',
        'reader_pcsc',
        'request_payload',
        'status',
        'result_payload',
        'error_message',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'result_payload' => 'array',
            'processed_at' => 'datetime',
        ];
    }
}
