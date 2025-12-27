<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IncidentType extends Model
{
    /** @use HasFactory<\Database\Factories\IncidentTypeFactory> */
    use HasFactory;
    protected $fillable = ['name', 'description'];


    /**
     * Get all of the generalAttendance for the IncidentType
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function generalAttendances(): HasMany
    {
        return $this->hasMany(GeneralAttendance::class, 'incident_type');
    }
}
