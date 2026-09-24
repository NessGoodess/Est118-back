<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CardDesign extends Model
{
    protected $fillable = [
        'uuid',
        'user_id',
        'grade_level_id',
        'name',
        'audience',
        'description',
        'orientation',
        'faces_mode',
        'is_active',
        'is_shared',
        'is_default',
        'legacy_key',
        'layout_json',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_shared' => 'boolean',
            'is_default' => 'boolean',
            'layout_json' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CardDesign $design): void {
            if (empty($design->uuid)) {
                $design->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    public function storageDir(): string
    {
        return storage_path('app/card-templates/'.$this->uuid);
    }

    /**
     * @param  Builder<CardDesign>  $query
     * @return Builder<CardDesign>
     */
    public function scopeVisibleTo(Builder $query, User $user): Builder
    {
        if ($user->hasRole('admin')) {
            return $query;
        }

        return $query->where(function (Builder $inner) use ($user) {
            $inner->where('user_id', $user->id)->orWhere('is_shared', true);
        });
    }
}
