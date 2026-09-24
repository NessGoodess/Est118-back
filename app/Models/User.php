<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;

use App\Notifications\VerifyEmailCustom;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasApiTokens, HasFactory, Notifiable, HasRoles, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Send the email verification notification.
     *
     * @return void
     */

    public function sendEmailVerificationNotification()
    {
        $this->notify(new VerifyEmailCustom);
    }

    /**
     * Get the profile associated with the User
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasOne
     */
    public function profile(): HasOne
    {
        return $this->hasOne(Profile::class);
    }

    /**
     * Get all of the recordedAttendances for the User
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function recordedAttendances(): HasMany
    {
        return $this->hasMany(GeneralAttendance::class, 'recorded_by');
    }

    public function linkedTeacherId(): ?int
    {
        $this->loadMissing('profile.teacher');
        $id = $this->profile?->teacher?->id;

        return $id ? (int) $id : null;
    }

    /**
     * @return 'all'|'group'|'own'|'none'
     */
    public function scheduleScope(): string
    {
        if ($this->can('view all schedules')) {
            return 'all';
        }
        if ($this->can('view group schedules')) {
            return 'group';
        }
        if ($this->can('view own schedules')) {
            return 'own';
        }

        return 'none';
    }

    public function ownsSchedule(Schedule $schedule): bool
    {
        $teacherId = $this->linkedTeacherId();
        if ($teacherId === null) {
            return false;
        }

        $schedule->loadMissing('schoolClass');

        return (int) $schedule->teacher_id === $teacherId
            || (int) ($schedule->schoolClass?->teacher_id ?? 0) === $teacherId;
    }

    public function cardDesigns(): HasMany
    {
        return $this->hasMany(CardDesign::class);
    }
}
