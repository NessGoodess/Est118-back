<?php

namespace App\Models\School;

use App\Enums\ReEnrollmentEventAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReEnrollmentEvent extends Model
{
    protected $fillable = [
        're_enrollment_period_id',
        'action',
        'user_id',
        'summary',
    ];

    protected $casts = [
        'action' => ReEnrollmentEventAction::class,
        'summary' => 'array',
    ];

    public function period(): BelongsTo
    {
        return $this->belongsTo(ReEnrollmentPeriod::class, 're_enrollment_period_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
