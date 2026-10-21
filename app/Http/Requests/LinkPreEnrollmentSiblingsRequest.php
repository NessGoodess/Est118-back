<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LinkPreEnrollmentSiblingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'sibling_student_ids' => ['required', 'array'],
            'sibling_student_ids.*' => ['integer', 'exists:students,id'],
        ];
    }
}
