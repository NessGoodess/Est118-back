<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class IdCard extends Model
{
    /** @use HasFactory<\Database\Factories\IdCardFactory> */
    use HasFactory;
    protected $fillable = [
        'student_id',
        'academic_year_id',
        'photo_path',
        'status',
        'is_active',
        'nfc_uid',
        'qr_code',
        'qr_image_path',
    ];

    /**
     * Get the student that owns the IdCard
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }

    /**
     * Get the academicYear that owns the IdCard
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * Get all of the printHistory for the IdCard
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany
     */
    public function printHistory(): HasMany
    {
        return $this->hasMany(IdCardPrintLog::class);
    }
}
