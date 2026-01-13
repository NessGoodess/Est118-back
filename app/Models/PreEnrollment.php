<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PreEnrollment extends Model
{
    /** @use HasFactory<\Database\Factories\PreEnrollmentFactory> */
    use HasFactory;

    protected $fillable = [
        'folio',
        'status',
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
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'has_siblings' => 'boolean',
        'has_school_voucher' => 'boolean',
    ];
}
