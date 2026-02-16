<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Guardian extends Model
{
    /** @use HasFactory<\Database\Factories\GuardianFactory> */
    use HasFactory;
    protected $fillable = [
        'profile_id',
        'Kinship',
        'telegram_id',
        'telegram_username',
        'telegram_notifications',
    ];

    /**
     * Get the profile that owns the Guardian
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * The students that belong to the Guardian
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsToMany
     */
    public function students(): BelongsToMany
    {
        return $this->belongsToMany(Student::class, 'guardian_student');
    }

    protected static function booted()
    {
        static::saving(function ($guardian) {
            if ($guardian->telegram_id) {
                $exists = static::where('telegram_id', $guardian->telegram_id)
                    ->where('id', '!=', $guardian->id)
                    ->exists();

                if ($exists) {
                    throw new \Exception('El ID de Telegram ya está registrado por otro tutor');
                }
            }
        });
    }
}
