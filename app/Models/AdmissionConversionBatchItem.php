<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdmissionConversionBatchItem extends Model
{
    protected $fillable = [
        'batch_id',
        'pre_enrollment_id',
        'status',
        'student_id',
        'enrollment_id',
        'error_code',
        'message',
        'exception_flags',
    ];

    protected function casts(): array
    {
        return [
            'exception_flags' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(AdmissionConversionBatch::class, 'batch_id');
    }

    public function preEnrollment(): BelongsTo
    {
        return $this->belongsTo(PreEnrollment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function toApiArray(): array
    {
        $pre = $this->relationLoaded('preEnrollment') ? $this->preEnrollment : null;

        return [
            'id' => $this->id,
            'pre_enrollment_id' => $this->pre_enrollment_id,
            'folio' => $pre?->folio,
            'status' => $this->status,
            'student_id' => $this->student_id,
            'enrollment_id' => $this->enrollment_id,
            'error_code' => $this->error_code,
            'message' => $this->message,
            'exception_flags' => $this->exception_flags,
            'replayed' => $this->status === 'skipped' && $this->student_id !== null,
        ];
    }
}
