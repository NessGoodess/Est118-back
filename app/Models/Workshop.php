<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Workshop extends Model
{
    /** @use HasFactory<\Database\Factories\WorkshopFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'teacher_id',
        'academic_year_id',
        'classroom_id',
        'capacity',
        'is_active',
    ];

    /**
     * Get the teacher that owns the Workshop
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function teacher(): BelongsTo
    {
        return $this->belongsTo(Teacher::class);
    }

    /**
     * The students that belong to the Workshop
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'workshop_enrollments')
            ->withPivot('academic_year_id');
    }

    /**
     * Get the eligible students for the Workshop based on grade level
     *
     * @return \Illuminate\Database\Eloquent\Builder
     */
    public function eligibleStudents()
    {
        return Student::whereHas('enrollments.classGroup', function ($query) {
            $query->where('grade_level_id', $this->grade_level_id);
        });
    }

    /**
     * Get the academicYear that owns the Workshop
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Get the classroom that owns the Workshop
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function classroom(): BelongsTo
    {
        return $this->belongsTo(Classroom::class);
    }
}
