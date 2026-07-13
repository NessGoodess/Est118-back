<?php

namespace App\Models\School;

use App\Enums\ReEnrollmentPeriodStatus;
use App\Enums\ReEnrollmentProcessStep;
use App\Models\AcademicYear;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReEnrollmentPeriod extends Model
{
    protected $fillable = [
        'name',
        'from_academic_year_id',
        'to_academic_year_id',
        'start_at',
        'end_at',
        'status',
        'current_step',
        'keep_current_groups',
        'created_by',
        'finalized_at',
        'promotion_executed_at',
        'promotion_executed_by',
        'finalized_by',
        'last_promotion_summary',
    ];

    protected $casts = [
        'status' => ReEnrollmentPeriodStatus::class,
        'current_step' => ReEnrollmentProcessStep::class,
        'keep_current_groups' => 'boolean',
        'start_at' => 'datetime',
        'end_at' => 'datetime',
        'finalized_at' => 'datetime',
        'promotion_executed_at' => 'datetime',
        'last_promotion_summary' => 'array',
    ];

    public function fromAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'from_academic_year_id');
    }

    public function toAcademicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class, 'to_academic_year_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function applications(): HasMany
    {
        return $this->hasMany(ReEnrollmentApplication::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(ReEnrollmentEvent::class);
    }

    public function promotionExecutedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'promotion_executed_by');
    }

    public function finalizedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'finalized_by');
    }

    public function isOpenNow(): bool
    {
        if ($this->status !== ReEnrollmentPeriodStatus::OPEN) {
            return false;
        }

        $now = now();

        if ($this->start_at && $now->lt($this->start_at)) {
            return false;
        }

        if ($this->end_at && $now->gt($this->end_at)) {
            return false;
        }

        return true;
    }
}
