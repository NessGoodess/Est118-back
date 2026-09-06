<?php

namespace App\Models\Content;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LegalDocument extends Model
{
    public const TYPE_PRIVACY = 'privacy';

    public const TYPE_SCHOOL_RULES = 'school_rules';

    public const TYPE_STUDENT_PHOTOS = 'student_photos';

    public const TYPES = [
        self::TYPE_PRIVACY,
        self::TYPE_SCHOOL_RULES,
        self::TYPE_STUDENT_PHOTOS,
    ];

    public const PUBLIC_TYPES = [
        self::TYPE_PRIVACY,
        self::TYPE_SCHOOL_RULES,
    ];

    protected $fillable = [
        'type',
        'title',
        'src',
        'original_name',
        'published_at',
        'updated_by',
    ];

    protected $casts = [
        'published_at' => 'datetime',
    ];

    public function editor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where('published_at', '<=', now());
    }

    public function isPublicType(): bool
    {
        return in_array($this->type, self::PUBLIC_TYPES, true);
    }

    public static function defaultTitle(string $type): string
    {
        return match ($type) {
            self::TYPE_PRIVACY => 'Aviso de privacidad',
            self::TYPE_SCHOOL_RULES => 'Reglamento escolar',
            self::TYPE_STUDENT_PHOTOS => 'Aviso de fotos de estudiantes',
            default => 'Documento',
        };
    }
}
