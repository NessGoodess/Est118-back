<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StudentCredentialTracking extends Model
{
    protected $fillable = [
        'student_id',
        'academic_year_id',
        'credential_printed',
        'nfc_ready',
        'ready_to_deliver',
        'paid',
        'delivered',
        'lost',
        'replacement_count',
    ];

    protected function casts(): array
    {
        return [
            'credential_printed' => 'boolean',
            'nfc_ready' => 'boolean',
            'ready_to_deliver' => 'boolean',
            'paid' => 'boolean',
            'delivered' => 'boolean',
            'lost' => 'boolean',
            'replacement_count' => 'integer',
        ];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
