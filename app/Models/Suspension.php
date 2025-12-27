<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Suspension extends Model
{
    /** @use HasFactory<\Database\Factories\SuspensionFactory> */
    use HasFactory;
protected $fillable = [
        'student_id',
        'reason',
        'start_date',
        'end_date',
        'type', // automatic, manual, disciplinary
        'status', // active, completed, cancelled
        'notes',
    ];

    protected $casts = [
        'start_date' => 'date',
        'end_date' => 'date',
    ];

    /**
     * Get the student that owns the Suspension
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
     public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Scope a query to only include active suspensions.
     *
     * @param  \Illuminate\Database\Eloquent\Builder  $query
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function scopeActive($query)
    {
        return $query->where('status', 'active')
                    ->where('start_date', '<=', now())
                    ->where('end_date', '>=', now());
    }
}

