<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeneralAttendance extends Model
{
    /** @use HasFactory<\Database\Factories\GeneralAttendanceFactory> */
    use HasFactory;
    protected $fillable = [
        'student_id',
        'academic_year_id',
        'date',
        'status', // present, late, absent
        'absence_request_id',
    ];

    protected $casts = [
        'date' => 'date',
    ];

    /**
     * Get the student that owns the GeneralAttendance
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get the academicYear that owns the GeneralAttendance
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Get the absenceRequest that owns the GeneralAttendance
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */

    public function absenceRequest(): BelongsTo
    {
        return $this->belongsTo(AbsenceRequest::class);
    }
}
