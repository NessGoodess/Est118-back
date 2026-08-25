<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Safe detail payload for admin UI — omits IP, user-agent and document storage paths.
 */
class PreEnrollmentDetailResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'folio' => $this->folio,
            'admission_cycle_id' => $this->admission_cycle_id,
            'status' => $this->status,
            'documents_status' => $this->documents_status,
            'payment_status' => $this->payment_status,
            'converted_student_id' => $this->converted_student_id,
            'converted_enrollment_id' => $this->converted_enrollment_id,
            'contact_email' => $this->contact_email,
            'first_name' => $this->first_name,
            'last_name' => $this->last_name,
            'second_last_name' => $this->second_last_name,
            'curp' => $this->curp,
            'birth_date' => $this->birth_date,
            'age' => $this->age,
            'gender' => $this->gender,
            'phone' => $this->phone,
            'student_email' => $this->student_email,
            'place_of_birth' => $this->place_of_birth,
            'previous_school' => $this->previous_school,
            'current_average' => $this->current_average,
            'admission_exam_score' => $this->admission_exam_score,
            'has_siblings' => $this->has_siblings,
            'siblings_details' => $this->siblings_details,
            'street_type' => $this->street_type,
            'street_name' => $this->street_name,
            'house_number' => $this->house_number,
            'unit_number' => $this->unit_number,
            'neighborhood_type' => $this->neighborhood_type,
            'neighborhood_name' => $this->neighborhood_name,
            'postal_code' => $this->postal_code,
            'city' => $this->city,
            'state' => $this->state,
            'guardian_first_name' => $this->guardian_first_name,
            'guardian_last_name' => $this->guardian_last_name,
            'guardian_second_last_name' => $this->guardian_second_last_name,
            'guardian_curp' => $this->guardian_curp,
            'guardian_phone' => $this->guardian_phone,
            'guardian_relationship' => $this->guardian_relationship,
            'workshop_first_choice' => $this->workshop_first_choice,
            'workshop_second_choice' => $this->workshop_second_choice,
            'has_school_voucher' => $this->has_school_voucher,
            'school_voucher_folio' => $this->school_voucher_folio,
            'reviewed_by' => $this->reviewed_by,
            'reviewed_at' => $this->reviewed_at,
            'review_notes' => $this->review_notes,
            'converted_by' => $this->converted_by,
            'converted_at' => $this->converted_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
