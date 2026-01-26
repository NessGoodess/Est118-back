<?php

namespace App\Models;

use App\Models\Admission\AdmissionCycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PreEnrollment extends Model
{
    /** @use HasFactory<\Database\Factories\PreEnrollmentFactory> */
    use HasFactory;

    protected $fillable = [
        'admission_cycle_id',
        'contact_email',
        'first_name',
        'last_name',
        'second_last_name',
        'curp',
        'birth_date',
        'age',
        'gender',
        'phone',
        'student_email',
        'place_of_birth',
        'previous_school',
        'current_average',
        'has_siblings',
        'siblings_details',
        'street_type',
        'street_name',
        'house_number',
        'unit_number',
        'neighborhood_type',
        'neighborhood_name',
        'postal_code',
        'city',
        'state',
        'guardian_first_name',
        'guardian_last_name',
        'guardian_second_last_name',
        'guardian_curp',
        'guardian_phone',
        'guardian_relationship',
        'workshop_first_choice',
        'workshop_second_choice',
        'has_school_voucher',
        'school_voucher_folio',
        'birth_certificate_path',
        'curp_document_path',
        'address_proof_path',
        'study_certificate_path',
        'photo_path',
    ];

    protected $casts = [
        'has_siblings' => 'boolean',
        'has_school_voucher' => 'boolean',
    ];

    private static function generateFolio(): string
    {
        $year = now()->year;

        return sprintf(
            'PRE-EST118-%d-%s',
            $year,
            strtoupper(Str::random(8))
        );
    }

    protected static function booted()
    {
        static::creating(function ($preEnrollment) {
            if (empty($preEnrollment->folio)) {
                $preEnrollment->folio = self::generateFolio();
            }
        });
    }

    /**
     * Get the admission cycle that owns the PreEnrollment
     *
     * @return \Illuminate\Database\Eloquent\Relations\BelongsTo
     */
    public function admission_cycle(): BelongsTo
    {
        return $this->belongsTo(AdmissionCycle::class);
    }
}