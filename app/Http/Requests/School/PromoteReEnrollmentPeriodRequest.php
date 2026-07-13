<?php

namespace App\Http\Requests\School;

use Illuminate\Foundation\Http\FormRequest;

class PromoteReEnrollmentPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}
