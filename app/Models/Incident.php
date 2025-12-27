<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Incident extends Model
{
    /** @use HasFactory<\Database\Factories\IncidentFactory> */
    use HasFactory;

    protected $fillable = [
        'student_id',
        'academic_year_id',
        'incident_type_id',
        'incident_notes',
        'recorded_by',
        'severity',
        'incident_date',
        'evidence',
        'status',
        'resolution',
    ];


    protected $casts = [
        'incident_date' => 'datetime',
        'evidence' => 'array',
    ];

    /**
     * Get the student that owns the Incidents
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get the reportedBy that owns the Incidents
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphTo
     */
    public function reportedBy(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * Get the academicYear that owns the Incident
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Get the incidentType that owns the Incident
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function incidentType(): BelongsTo
    {
        return $this->belongsTo(IncidentType::class);
    }
}
