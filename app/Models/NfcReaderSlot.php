<?php

namespace App\Models;

use App\Enums\NfcReaderAudience;
use App\Enums\NfcReaderDirection;
use Illuminate\Database\Eloquent\Model;

class NfcReaderSlot extends Model
{
    protected $fillable = [
        'code',
        'label',
        'audience',
        'direction',
        'sort_order',
        'pcsc_name',
        'is_active',
        'is_armed',
        'last_seen_at',
    ];

    protected $casts = [
        'audience' => NfcReaderAudience::class,
        'direction' => NfcReaderDirection::class,
        'is_active' => 'boolean',
        'is_armed' => 'boolean',
        'last_seen_at' => 'datetime',
    ];
}
