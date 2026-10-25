<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentCredentialTrackingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Delivery staff may only update payment / handoff flags.
     * Impresa, NFC, listo and repuesto # are written by print/NFC/reprint services.
     */
    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'paid' => ['sometimes', 'boolean'],
            'delivered' => ['sometimes', 'boolean'],
            'lost' => ['sometimes', 'boolean'],
        ];
    }
}
