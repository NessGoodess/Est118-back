<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentCredentialTrackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'credential_printed' => ['sometimes', 'boolean'],
            'nfc_ready' => ['sometimes', 'boolean'],
            'ready_to_deliver' => ['sometimes', 'boolean'],
            'paid' => ['sometimes', 'boolean'],
            'delivered' => ['sometimes', 'boolean'],
            'lost' => ['sometimes', 'boolean'],
            'replacement_count' => ['sometimes', 'integer', 'min:0', 'max:50'],
        ];
    }
}
