<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ConvertPreEnrollmentToStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'class_group_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
