<?php

namespace App\Http\Requests\School;

use Illuminate\Foundation\Http\FormRequest;

class FinalizeReEnrollmentPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'activate_academic_year' => ['sometimes', 'boolean'],
        ];
    }
}
