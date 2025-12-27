<?php

namespace App\Models;

use App\Enums\TeacherStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Teacher extends Model
{
    /** @use HasFactory<\Database\Factories\TeacherFactory> */
    use HasFactory;
protected $fillable = [
        'profile_id',
        'employee',
        'status',
    ];

    protected $casts = [
        'status' => TeacherStatus::class,
    ];

    /**
     * Get the profile that owns the Teacher
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function profile(): BelongsTo
    {
        return $this->belongsTo(Profile::class);
    }

    /**
     * Get all of the schoolClasses for the Teacher
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function schoolClasses()
    {
        return $this->hasMany(SchoolClass::class);
    }

    /**
     * Get all of the incidents for the Teacher
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function incidents(): MorphMany
    {
        return $this->morphMany(Incident::class, 'reported_by');
    }

    /**
     * Get all of the absenceRequests for the Teacher
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function absenceRequest(): MorphMany
    {
        return $this->morphMany(AbsenceRequest::class, 'reviewed_by_id');
    }

    /**
     * Get all of the generatedBy for the Teacher
     *
     * @return \Illuminate\Database\Eloquent\Relations\MorphMany
     */
    public function generatedBy(): MorphMany
    {
        return $this->morphMany(IdCardPrintLog::class, 'generated_by');
    }

    /**
     * Get all of the authorizedPermissions for the Teacher
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function authorizedPermissions(): HasMany
    {
        return $this->hasMany(StudentPermission::class, 'authorized_by');
    }
}

