<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkInitialReviewPreEnrollmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'cycle_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
