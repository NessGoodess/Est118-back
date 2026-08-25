<?php

namespace App\Http\Requests;

use App\Services\AdmissionIdempotencyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class StoreAdmissionConversionBatchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $early = app(AdmissionIdempotencyService::class)->resolve(
            $this,
            AdmissionIdempotencyService::SCOPE_BATCH
        );

        if ($early) {
            throw new HttpResponseException($early);
        }
    }

    public function rules(): array
    {
        return [
            'pre_enrollment_ids' => ['required', 'array', 'min:1', 'max:300'],
            'pre_enrollment_ids.*' => ['required', 'integer', 'distinct', 'min:1'],
            'academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'channel' => ['sometimes', Rule::in(['campaign', 'late'])],
            'dry_run' => ['sometimes', 'boolean'],
            'expected_count' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
