<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfileAccessInvite extends Model
{
    protected $fillable = [
        'profile_id',
        'email',
        'token_hash',
        'roles',
        'permissions',
        'invited_by',
        'expires_at',
        'accepted_at',
        'accepted_user_id',
    ];

    protected function casts(): array
    {
        return [
            'roles' => 'array',
            'permissions' => 'array',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
        ];
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    public function invitedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function acceptedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'accepted_user_id');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->whereNull('accepted_at')->where('expires_at', '>', now());
    }

    public function isPending(): bool
    {
        return $this->accepted_at === null && $this->expires_at !== null && $this->expires_at->isFuture();
    }

    public function isExpired(): bool
    {
        return $this->accepted_at === null && $this->expires_at !== null && $this->expires_at->isPast();
    }
}
