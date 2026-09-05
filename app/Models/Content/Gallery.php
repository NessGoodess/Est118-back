<?php

namespace App\Models\Content;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gallery extends Model
{
    protected $table = 'galleries';

    protected $fillable = [
        'slug',
        'title',
        'description',
        'category',
        'cover_src',
        'featured',
        'published_at',
        'created_by',
    ];

    protected $casts = [
        'featured' => 'bool',
        'published_at' => 'datetime',
    ];

    /**
     * Photos of the album, already ordered.
     */
    public function items(): HasMany
    {
        return $this->hasMany(GalleryItem::class)->orderBy('sort_order');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Albums visible on the public site.
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /**
     * Cover falls back to the first photo when no explicit cover was set.
     */
    public function resolvedCover(): ?string
    {
        if (filled($this->cover_src)) {
            return $this->cover_src;
        }

        return $this->relationLoaded('items')
            ? $this->items->first()?->media_src
            : $this->items()->value('media_src');
    }
}
