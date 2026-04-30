<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Announcement extends Model
{
    /** @use HasFactory<\Database\Factories\AnnouncementFactory> */
    use HasFactory;

    protected $fillable = [
        'slug',
        'header',
        'title',
        'header_alert_enabled',
        'header_alert_label',
        'content_type',
        'content_text',
        'content_items',
        'primary_button_label',
        'primary_button_href',
        'primary_button_action',
        'secondary_button_enabled',
        'secondary_button_label',
        'secondary_button_href',
        'media_type',
        'media_src',
        'media_youtube_id',
        'media_alt',
        'media_ratio',
        'published_at',
        'author',
        'type',
        'important',
        'summary',
        'content_blocks',
        'created_by',
    ];

    protected $casts = [
        'header_alert_enabled' => 'bool',
        'secondary_button_enabled' => 'bool',
        'important' => 'bool',
        'content_items' => 'array',
        'content_blocks' => 'array',
        'published_at' => 'datetime',
    ];

    /**
     * Author of the announcement.
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}

