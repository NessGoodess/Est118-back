<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ClassStudent extends Model
{
    /** @use HasFactory<\Database\Factories\ClassStudentFactory> */
    use HasFactory;
    protected $table = 'class_students';

    protected $fillable = [
        'student_id',
        'schedule_id',
        'status',
    ];

    /**
     * Get the student that owns the ClassStudent
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function  student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get the schedule that owns the ClassStudent
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function schedule(): BelongsTo
    {
        return $this->belongsTo(Schedule::class);
    }
}
