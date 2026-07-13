<?php

namespace App\Http\Requests\School;

use Illuminate\Foundation\Http\FormRequest;

class StoreReEnrollmentPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'from_academic_year_id' => ['required', 'integer', 'exists:academic_years,id'],
            'to_academic_year_id' => ['required', 'integer', 'exists:academic_years,id', 'different:from_academic_year_id'],
            'start_at' => ['required', 'date'],
            'end_at' => ['required', 'date', 'after:start_at'],
            'keep_current_groups' => ['sometimes', 'boolean'],
        ];
    }
}
