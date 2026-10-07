<?php

namespace App\Models;

use App\Enums\StaffStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Staff extends Model
{
    /** @use HasFactory<\Database\Factories\StaffFactory> */
    use HasFactory;

    protected $fillable = [
        'profile_id',
        'position',
        'department',
        'status',
    ];

    protected $casts = [
        'status' => StaffStatus::class,
    ];

    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * Get all of the incidents for the Staff
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function incidents(): MorphMany
    {
        return $this->morphMany(Incident::class, 'created_by');
    }

    /**
     * Get all of the absenceRequests for the Staff
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function absenceRequest(): MorphMany
    {
        return $this->morphMany(AbsenceRequest::class, 'reviewed_by_id');
    }

    /**
     * Get all of the idCardPrintLogs for the Staff
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function generatedBy(): MorphMany
    {
        return $this->morphMany(IdCardPrintLog::class, 'generated_by');
    }
}
