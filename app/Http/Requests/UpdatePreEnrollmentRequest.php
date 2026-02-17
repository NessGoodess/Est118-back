<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdatePreEnrollmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $preEnrollment = $this->route('preEnrollment');
        $preEnrollmentId = is_object($preEnrollment) ? $preEnrollment->id : $preEnrollment;

        return [
            'contact_email' => 'required|email|max:100',
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'second_last_name' => 'nullable|string|max:100',
            'curp' => 'required|string|size:18|regex:/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/|unique:pre_enrollments,curp,' . $preEnrollmentId,
            'birth_date' => 'required|date',
            'age' => 'required|integer|between:10,18',
            'gender' => 'required|in:M,F,O',
            'phone' => 'required|string|max:15',
            'student_email' => 'required|email|max:100',
            'place_of_birth' => 'required|string|max:100',
            'previous_school' => 'required|string|max:100',
            'current_average' => 'required|numeric|between:0,10',
            'has_siblings' => 'required|boolean',
            'siblings_details' => 'nullable|string|max:255',
            'street_type' => 'required|string|max:100',
            'street_name' => 'required|string|max:100',
            'house_number' => 'required|string|max:100',
            'unit_number' => 'nullable|string|max:100',
            'neighborhood_type' => 'required|string|max:100',
            'neighborhood_name' => 'required|string|max:100',
            'postal_code' => 'required|string|size:5',
            'city' => 'required|string|max:100',
            'state' => 'required|string|max:100',
            'guardian_first_name' => 'required|string|max:100',
            'guardian_last_name' => 'required|string|max:100',
            'guardian_second_last_name' => 'nullable|string|max:100',
            'guardian_curp' => 'required|string|size:18|regex:/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/',
            'guardian_phone' => 'required|string|max:15',
            'guardian_relationship' => 'required|string|max:100',
            'workshop_first_choice' => 'required|string|max:100',
            'workshop_second_choice' => 'required|string|max:100',
            'has_school_voucher' => 'required|boolean',
            'school_voucher_folio' => 'nullable|string|max:100',
        ];
    }
}
