<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Workshop extends Model
{
    /** @use HasFactory<\Database\Factories\WorkshopFactory> */
    use HasFactory;

    public const OFIMATICA_CODE = 'OFIMATICA';

    protected $fillable = [
        'name',
        'code',
        'description',
        'is_active',
        'is_internal',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'is_internal' => 'boolean',
        ];
    }

    public function offerings(): HasMany
    {
        return $this->hasMany(WorkshopOffering::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(WorkshopEnrollment::class);
    }

    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'workshop_enrollments')
            ->withPivot(['academic_year_id', 'source', 'status', 'assigned_by', 'notes'])
            ->withTimestamps();
    }

    public function schedules(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }
}
