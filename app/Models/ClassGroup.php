<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

class ClassGroup extends Model
{
    /** @use HasFactory<\Database\Factories\ClassGroupFactory> */
    use HasFactory;
    protected $fillable = [
        'academic_year_id',
        'grade_level_id',
        'name',
    ];

    /**
     * Get the academicYear that owns the ClassGroup
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Get the gradeLwevel that owns the ClassGroup
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function gradeLevel(): BelongsTo
    {
        return $this->belongsTo(GradeLevel::class);
    }

    /**
     * Get all of the enrollments for the ClassGroup
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }


    /**
     * Get all of the students for the ClassGroup
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function students(): HasManyThrough
    {
        return $this->hasManyThrough(
            Student::class,
            Enrollment::class,
            'class_group_id', // FK on enrollments
            'id',             // FK on students
            'id',             // PK on class_groups
            'student_id'      // FK -> students
        );
    }

    /**
     * Get all of the schoolClases for the ClassGroup
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function schoolClasses()
    {
        return $this->hasMany(SchoolClass::class);
    }

    /**
     * Get all of the schedules for the ClassGroup
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasManyThrough
     */
    public function schedules(): HasManyThrough
    {
        return $this->hasManyThrough(
            Schedule::class,
            SchoolClass::class,
            'class_group_id',   // Foreign key on school_classes
            'school_class_id', // Foreign key on schedules
            'id',             // Local key on class_groups
            'id'             // Local key on school_classes
        );
    }
}
