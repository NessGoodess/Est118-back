<?php

namespace App\Models;

use App\Models\Admission\AdmissionCycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;

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

    protected static function booted()
    {
        static::creating(function ($preEnrollment) {

            if ($preEnrollment->folio) {
                return;
            }

            DB::transaction(function () use ($preEnrollment) {

                $cycle = AdmissionCycle::where('id', $preEnrollment->admission_cycle_id)
                    ->lockForUpdate()
                    ->first();

                if (! $cycle) {
                    throw new \Exception('El PreEnrollment debe pertenecer a un ciclo de admisión.');
                }

                $cycle->increment('last_folio_number');

                $preEnrollment->folio = sprintf('%03d', $cycle->last_folio_number);

            });
        });
    }

    /**
     * Get the admission cycle that owns the PreEnrollment
     */
    public function admission_cycle(): BelongsTo
    {
        return $this->belongsTo(AdmissionCycle::class);
    }
}
