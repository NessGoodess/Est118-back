<?php

namespace App\Models\Admission;

use App\Models\PreEnrollment;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AdmissionCycle extends Model
{
    /** @use HasFactory<\Database\Factories\Admission\AdmissionCycleFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'start_at',
        'end_at',
        //'status',
        'created_by',
    ];

    protected $casts = [
        
        'start_at' => 'datetime',
        'end_at' => 'datetime',
    ];

    /**
     * Get all of the preenrollments for the AdmissionCycle
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function preenrollments(): HasMany
    {
        return $this->hasMany(PreEnrollment::class);
    }


}
