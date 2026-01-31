<?php

namespace App\Models\Admission;

use App\Models\PreEnrollment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use App\Enums\AdmissionCycleStatus;

class AdmissionCycle extends Model
{
    /** @use HasFactory<\Database\Factories\Admission\AdmissionCycleFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'start_at',
        'end_at',
        'status',
        'created_by',
    ];

    protected $casts = [
        'status' => AdmissionCycleStatus::class,
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    /**
     * Get all of the preenrollments for the AdmissionCycle
     */
    public function preenrollments(): HasMany
    {
        return $this->hasMany(PreEnrollment::class);
    }

    public function publicStatus(): string
    {
        $now = now();

        if ($this->status !== AdmissionCycleStatus::ACTIVE) {
            return 'not_available';
        }

        if ($now->lt($this->start_at)) {
            return 'not_started';
        }

        if ($now->gt($this->end_at)) {
            return 'ended';
        }

        return 'active';
    }
}
