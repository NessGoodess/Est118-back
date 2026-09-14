<?php

namespace App\Models;

use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkshopEnrollment extends Model
{
    protected $fillable = [
        'student_id',
        'workshop_id',
        'academic_year_id',
        'source',
        'status',
        'assigned_by',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'source' => WorkshopEnrollmentSource::class,
            'status' => WorkshopEnrollmentStatus::class,
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function workshop(): BelongsTo
    {
        return $this->belongsTo(Workshop::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function assignedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_by');
    }

    public function isProtectedFromBatch(): bool
    {
        return $this->source === WorkshopEnrollmentSource::Manual
            || $this->source === WorkshopEnrollmentSource::Inherited;
    }
}
