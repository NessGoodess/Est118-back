<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Profile extends Model
{
    /** @use HasFactory<\Database\Factories\ProfileFactory> */
    use HasFactory;

protected $fillable = [
        'user_id',
        'national_id',
        'first_name',
        'last_name',
        'second_last_name',
        'birth_date',
        'email',
        'phone_number',
        'phone_second_number',
        'insurance_type',
        'insurance_number',
        'profile_picture',
        'gender',
        'address_id',
    ];

    public function setNationalIdAttribute(?string $value): void
    {
        $this->attributes['national_id'] = $value === null ? null : strtoupper(trim($value));
    }

    /** Paternal + maternal surname. Older rows hold both inside last_name. */
    public function fullLastName(): string
    {
        return trim(($this->last_name ?? '').' '.($this->second_last_name ?? ''));
    }

    public function fullName(): string
    {
        return trim(($this->first_name ?? '').' '.$this->fullLastName());
    }

    /**
     * Get the user that owns the profile
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the student associated with the profile
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function student(): HasOne
    {
        return $this->hasOne(Student::class);
    }

    /**
     * Get the teacher associated with the profile
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function teacher(): HasOne
    {
        return $this->hasOne(Teacher::class);
    }

    /**
     * Get the guardian associated with the profile
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function guardian(): HasOne
    {
        return $this->hasOne(Guardian::class);
    }

    public function staff(): HasOne
    {
        return $this->hasOne(Staff::class);
    }

    public function address(): BelongsTo
    {
        return $this->belongsTo(Address::class);
    }

    public function emergencyContacts(): HasMany
    {
        return $this->hasMany(EmergencyContact::class)->orderBy('sort_order')->orderBy('id');
    }
}
