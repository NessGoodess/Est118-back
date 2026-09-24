<?php

namespace App\Models;

use App\Enums\PrintJobStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PrintJob extends Model
{
    protected $fillable = [
        'uuid',
        'student_id',
        'printer_id',
        'template_key',
        'card_design_id',
        'side_mode',
        'status',
        'priority',
        'payload_json',
        'front_path',
        'back_path',
        'claimed_by',
        'claimed_at',
        'started_at',
        'completed_at',
        'attempts',
        'max_attempts',
        'last_error',
        'created_by',
        'academic_year_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => PrintJobStatus::class,
            'payload_json' => 'array',
            'priority' => 'integer',
            'attempts' => 'integer',
            'max_attempts' => 'integer',
            'claimed_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PrintJob $job): void {
            if (empty($job->uuid)) {
                $job->uuid = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function cardDesign(): BelongsTo
    {
        return $this->belongsTo(CardDesign::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }
}
