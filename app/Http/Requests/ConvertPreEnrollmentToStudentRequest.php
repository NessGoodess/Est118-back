<?php

namespace App\Http\Requests;

use App\Services\AdmissionIdempotencyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ConvertPreEnrollmentToStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $early = app(AdmissionIdempotencyService::class)->resolve(
            $this,
            AdmissionIdempotencyService::SCOPE_CONVERT
        );

        if ($early) {
            throw new HttpResponseException($early);
        }
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['sometimes', 'nullable', 'integer', 'exists:academic_years,id'],
            'class_group_id' => ['sometimes', 'nullable', 'integer', 'exists:class_groups,id'],
            'channel' => ['sometimes', Rule::in(['campaign', 'late'])],
            'force_incomplete_docs' => ['sometimes', 'boolean'],
            'force_incomplete_data' => ['sometimes', 'boolean'],
            'force_without_payment' => ['sometimes', 'boolean'],
        ];
    }
}
