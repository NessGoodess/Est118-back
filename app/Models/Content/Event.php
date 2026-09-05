<?php

namespace App\Models\Content;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    /** Types shared by /eventos and the public school calendar. */
    public const TYPES = [
        'Ceremonia',
        'Feria',
        'Torneo',
        'Examen',
        'Cultural',
        'Deportivo',
        'Académico',
        'Junta',
        'Entrega',
        'Suspensión',
        'Vacaciones',
    ];

    protected $fillable = [
        'slug',
        'title',
        'type',
        'summary',
        'content_blocks',
        'starts_at',
        'ends_at',
        'location',
        'cover_src',
        'important',
        'gallery_id',
        'published_at',
        'created_by',
    ];

    protected $casts = [
        'content_blocks' => 'array',
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'published_at' => 'datetime',
        'important' => 'bool',
    ];

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    /** Events that have not finished yet. */
    public function scopeUpcoming(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->where('starts_at', '>=', now())
                ->orWhere('ends_at', '>=', now());
        });
    }
}
