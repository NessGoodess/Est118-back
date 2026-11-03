<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileUserLinkAudit extends Model
{
    public const ACTION_LINK = 'link';

    public const ACTION_INVITE_ACCEPT = 'invite_accept';

    public const ACTION_INVITE_RESTORE = 'invite_restore';

    protected $fillable = [
        'profile_id',
        'user_id',
        'linked_by',
        'action',
        'email_matched',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'email_matched' => 'boolean',
            'meta' => 'array',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function linkedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'linked_by');
    }
}
