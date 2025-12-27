<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AbsenceRequest extends Model
{
    /** @use HasFactory<\Database\Factories\AbsenceRequestFactory> */
     use HasFactory;

    protected $fillable = [
        'student_id',
        'guardian_id',
        'academic_year_id',
        'type'.
        'request_date',
        'start_date',
        'end_date',
        'reason',
        'evidence',
        'status',
        'reviewed_by_id',
        'reviewed_by_type',
        'reviewed_at'
    ];

    protected $casts = [
        'evidence' => 'array',
        'start_date' => 'date',
        'end_date' => 'date',
        'request_date' => 'date',
        'reviewed_at' => 'datetime',
    ];

    /**
     * Get the student that owns the AbsenceRequest
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get the guardian that owns the AbsenceRequest
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function guardian(): BelongsTo
    {
        return $this->belongsTo(Guardian::class);
    }

    /**
     * Get the academicYear that owns the AbsenceRequest
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function reviewedBy()
    {
        return $this->morphTo();
    }

    /**
     * Get all of the attendances for the AbsenceRequest
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function attendances(): HasMany
    {
        return $this->hasMany(Attendance::class);
    }

    /**
     * Get all of the attendances for the AbsenceRequest
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function generalAttendances(): HasMany
    {
        return $this->hasMany(GeneralAttendance::class);
    }
}
