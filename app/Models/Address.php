<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Address extends Model
{
    /** @use HasFactory<\Database\Factories\AddressFactory> */
    use HasFactory;
    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
    protected $fillable = [
        'street_type',
        'street_name',
        'house_number',
        'unit_number',
        'neighborhood_type',
        'neighborhood_name',
        'postal_code',
        'city',
        'state',
    ];

    public function getApartamentNumberAttribute(): ?string
    {
        return $this->attributes['unit_number'] ?? null;
    }

    public function setApartamentNumberAttribute(?string $value): void
    {
        $this->attributes['unit_number'] = $value;
    }

    /**
     * Get the profile associated with the Address
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }
}
