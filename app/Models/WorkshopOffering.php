<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class WorkshopOffering extends Model
{
    protected $fillable = [
        'workshop_id',
        'academic_year_id',
        'capacity',
        'is_open_for_intake',
    ];

    protected function casts(): array
    {
        return [
            'capacity' => 'integer',
            'is_open_for_intake' => 'boolean',
        ];
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(WorkshopEnrollment::class, 'workshop_id', 'workshop_id')
            ->where('academic_year_id', $this->academic_year_id);
    }
}
