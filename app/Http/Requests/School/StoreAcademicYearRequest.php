<?php

namespace App\Http\Requests\School;

use Illuminate\Foundation\Http\FormRequest;

class StoreAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'year_start' => ['required', 'digits:4'],
            'year_end' => ['required', 'digits:4', 'gt:year_start'],
            'description' => ['sometimes', 'string', 'max:100'],
            'generate_class_groups' => ['sometimes', 'boolean'],
        ];
    }
}
