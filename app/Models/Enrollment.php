<?php

namespace App\Models;

use App\Enums\EnrollmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Enrollment extends Model
{
    /** @use HasFactory<\Database\Factories\EnrollmentFactory> */
    use HasFactory;
    protected $fillable = [
        'student_id',
        'class_group_id',
        'academic_year_id',
        'status',
    ];

    protected $casts = [
        'status' => EnrollmentStatus::class,
    ];

    /**
     * Get the student that owns the Enrollment
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }


    /**
     * Get the classGroup that owns the Enrollment
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    public function getGradeNameAttribute()
    {
        return $this->classGroup->gradeLevel->name ?? 'N/A';
    }

    public function getGroupNameAttribute()
    {
        return $this->classGroup->name ?? 'N/A';
    }

    public function academicYear()
{
    return $this->belongsTo(AcademicYear::class);
}

}
