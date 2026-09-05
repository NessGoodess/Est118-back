<?php

namespace App\Models\Content;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IdentityBanner extends Model
{
    protected $fillable = [
        'slug',
        'src',
        'alt',
        'show_copy',
        'eyebrow',
        'title',
        'description',
        'show_cta',
        'href',
        'cta',
        'sort_order',
        'published_at',
        'created_by',
    ];

    protected $casts = [
        'show_copy' => 'bool',
        'show_cta' => 'bool',
        'sort_order' => 'int',
        'published_at' => 'datetime',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('id');
    }
}
