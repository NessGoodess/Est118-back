<?php

namespace App\Models;

use App\Enums\CredentialReprintReason;
use App\Enums\CredentialReprintStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class CredentialReprintRequest extends Model
{
    protected $fillable = [
        'uuid',
        'student_id',
        'academic_year_id',
        'class_group_id',
        'grade_label',
        'group_label',
        'reason',
        'notes',
        'requester_relation',
        'requester_name',
        'status',
        'is_chargeable',
        'requested_by',
        'reviewed_by',
        'reviewed_at',
        'reject_reason',
        'paid_by',
        'paid_at',
        'payment_folio',
        'payment_amount',
        'credential_print_id',
        'printed_at',
    ];

    protected function casts(): array
    {
        return [
            'reason' => CredentialReprintReason::class,
            'status' => CredentialReprintStatus::class,
            'is_chargeable' => 'boolean',
            'reviewed_at' => 'datetime',
            'paid_at' => 'datetime',
            'printed_at' => 'datetime',
            'payment_amount' => 'decimal:2',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (CredentialReprintRequest $request): void {
            if (empty($request->uuid)) {
                $request->uuid = (string) Str::uuid();
            }
        });

        static::saving(function (CredentialReprintRequest $request): void {
            $request->open_student_id = $request->status?->isOpen() ? $request->student_id : null;
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

    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    public function credentialPrint(): BelongsTo
    {
        return $this->belongsTo(CredentialPrint::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function payer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereIn('status', CredentialReprintStatus::open());
    }

    /** A new card for the student takes the request that was waiting to print. */
    public static function attachCard(CredentialPrint $card): void
    {
        if ($card->student_id === null) {
            return;
        }

        $request = self::query()
            ->where('student_id', $card->student_id)
            ->where('status', CredentialReprintStatus::ReadyToPrint)
            ->whereNull('credential_print_id')
            ->first();

        $request?->update([
            'status' => CredentialReprintStatus::Queued,
            'credential_print_id' => $card->id,
        ]);
    }

    /** Completed card closes the request; a discarded or failed one sends it back to the print list. */
    public static function syncFromCard(CredentialPrint $card): void
    {
        $request = self::query()
            ->where('credential_print_id', $card->id)
            ->where('status', CredentialReprintStatus::Queued)
            ->first();
        if (! $request) {
            return;
        }

        if ($card->completed_at !== null) {
            $request->update([
                'status' => CredentialReprintStatus::Printed,
                'printed_at' => $card->completed_at,
            ]);

            return;
        }

        if ($card->open_student_id === null) {
            $request->update([
                'status' => CredentialReprintStatus::ReadyToPrint,
                'credential_print_id' => null,
            ]);
        }
    }
}
