<?php

namespace App\Models;

use App\Enums\DocumentsStatus;
use App\Enums\PaymentStatus;
use App\Enums\PreEnrollmentStatus;
use App\Models\Admission\AdmissionCycle;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class PreEnrollment extends Model
{
    /** @use HasFactory<\Database\Factories\PreEnrollmentFactory> */
    use HasFactory;

    protected $fillable = [
        'admission_cycle_id',
        'status',
        'documents_status',
        'payment_status',
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
        'admission_exam_score',
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
        'converted_student_id',
        'converted_enrollment_id',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'converted_by',
        'converted_at',
        'conversion_options',
        'conversion_policy_snapshot',
    ];

    protected $casts = [
        'has_siblings' => 'boolean',
        'has_school_voucher' => 'boolean',
        'status' => PreEnrollmentStatus::class,
        'documents_status' => DocumentsStatus::class,
        'payment_status' => PaymentStatus::class,
        'current_average' => 'decimal:2',
        'admission_exam_score' => 'decimal:2',
        'reviewed_at' => 'datetime',
        'converted_at' => 'datetime',
        'conversion_options' => 'array',
        'conversion_policy_snapshot' => 'array',
    ];

    public function setCurpAttribute(?string $value): void
    {
        $this->attributes['curp'] = $value === null ? null : strtoupper(trim($value));
    }

    public function setGuardianCurpAttribute(?string $value): void
    {
        $this->attributes['guardian_curp'] = $value === null ? null : strtoupper(trim($value));
    }

    public function convertedStudent(): BelongsTo
    {
        return $this->belongsTo(Student::class, 'converted_student_id');
    }

    public function convertedEnrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class, 'converted_enrollment_id');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function convertedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'converted_by');
    }

    protected static function booted()
    {
        static::saving(function (self $preEnrollment) {
            // SQLite keeps a plain curp_normalized column; MySQL uses a generated one.
            if (DB::getDriverName() === 'mysql') {
                return;
            }
            if (! Schema::hasColumn('pre_enrollments', 'curp_normalized')) {
                return;
            }
            $preEnrollment->attributes['curp_normalized'] = $preEnrollment->attributes['curp'] ?? null;
        });

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
