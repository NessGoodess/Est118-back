<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GradeLevel extends Model
{
    /** @use HasFactory<\Database\Factories\GradeLevelFactory> */
    use HasFactory;
    protected $fillable = [
        'name',
        'description',
        'is_active',
    ];

    /**
     * Get all of the enrollments for the GradeLevel
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    /**
     * Get all of the schedule for the GradeLevel
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function schedule(): HasMany
    {
        return $this->hasMany(Schedule::class);
    }
}
