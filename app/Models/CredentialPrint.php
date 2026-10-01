<?php

namespace App\Models;

use App\Enums\CredentialPrintReason;
use App\Enums\CredentialSideStatus;
use App\Enums\PrintBatchStrategy;
use App\Enums\PrintJobStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class CredentialPrint extends Model
{
    protected $fillable = [
        'uuid',
        'student_id',
        'academic_year_id',
        'card_design_id',
        'faces_mode',
        'front_status',
        'back_status',
        'strategy',
        'batch_uuid',
        'reason',
        'created_by',
        'discarded_by',
        'discarded_at',
        'discard_reason',
        'front_printed_at',
        'back_printed_at',
        'completed_at',
        'open_student_id',
    ];

    protected function casts(): array
    {
        return [
            'front_status' => CredentialSideStatus::class,
            'back_status' => CredentialSideStatus::class,
            'strategy' => PrintBatchStrategy::class,
            'reason' => CredentialPrintReason::class,
            'discarded_at' => 'datetime',
            'front_printed_at' => 'datetime',
            'back_printed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CredentialPrint $card): void {
            if (empty($card->uuid)) {
                $card->uuid = (string) Str::uuid();
            }
        });

        static::saving(function (CredentialPrint $card): void {
            $card->open_student_id = self::openStudentId(
                $card->student_id,
                $card->discarded_at,
                $card->front_status,
                $card->back_status
            );
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

    public function cardDesign(): BelongsTo
    {
        return $this->belongsTo(CardDesign::class);
    }

    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function discarder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'discarded_by');
    }

    public function printJobs(): HasMany
    {
        return $this->hasMany(PrintJob::class);
    }

    public function isComplete(): bool
    {
        return $this->front_status->isDone() && $this->back_status->isDone();
    }

    public function needsFront(): bool
    {
        return $this->front_status === CredentialSideStatus::Pending && $this->discarded_at === null;
    }

    public function needsBack(): bool
    {
        return $this->front_status === CredentialSideStatus::Printed
            && $this->back_status === CredentialSideStatus::Pending
            && $this->discarded_at === null;
    }

    public static function openStudentId(mixed $studentId, mixed $discardedAt, mixed $front, mixed $back): ?int
    {
        if ($discardedAt !== null || $studentId === null) {
            return null;
        }

        $frontValue = $front instanceof \BackedEnum ? $front->value : (string) $front;
        $backValue = $back instanceof \BackedEnum ? $back->value : (string) $back;
        $open = $frontValue === CredentialSideStatus::Pending->value
            || ($frontValue === CredentialSideStatus::Printed->value
                && $backValue === CredentialSideStatus::Pending->value);

        return $open ? (int) $studentId : null;
    }

    /** Tarjetas sin terminar y no descartadas: bloquean un envío nuevo del mismo alumno. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query
            ->whereNull('discarded_at')
            ->where(function (Builder $q) {
                $q->where('front_status', CredentialSideStatus::Pending)
                    ->orWhere(function (Builder $inner) {
                        $inner->where('front_status', CredentialSideStatus::Printed)
                            ->where('back_status', CredentialSideStatus::Pending);
                    });
            });
    }

    /**
     * Por qué la tarjeta bloquea un envío nuevo, o null si no lo bloquea.
     * in_queue | needs_back | front_pending
     */
    public function openReason(): ?string
    {
        if ($this->discarded_at !== null) {
            return null;
        }
        if ($this->needsFront()) {
            return $this->activeJob() ? 'in_queue' : 'front_pending';
        }
        if ($this->needsBack()) {
            return $this->activeJob() ? 'in_queue' : 'needs_back';
        }

        return null;
    }

    public function activeJob(): ?PrintJob
    {
        if ($this->relationLoaded('printJobs')) {
            return $this->printJobs->first(fn (PrintJob $job) => in_array(
                $job->status,
                [
                    PrintJobStatus::Pending,
                    PrintJobStatus::Ready,
                    PrintJobStatus::Claimed,
                    PrintJobStatus::Printing,
                ],
                true
            ));
        }

        return $this->printJobs()
            ->whereIn('status', [
                PrintJobStatus::Pending,
                PrintJobStatus::Ready,
                PrintJobStatus::Claimed,
                PrintJobStatus::Printing,
            ])
            ->orderBy('id')
            ->first();
    }
}
