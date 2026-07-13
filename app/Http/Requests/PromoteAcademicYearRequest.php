<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PromoteAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'from_academic_year_id' => ['required', 'integer', 'min:1'],
            'to_academic_year_id' => ['required', 'integer', 'min:1', 'different:from_academic_year_id'],
            'dry_run' => ['sometimes', 'boolean'],
        ];
    }
}

