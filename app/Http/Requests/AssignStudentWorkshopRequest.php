<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignStudentWorkshopRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'workshop_id' => ['required', 'integer', 'min:1'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:500'],
            'force' => ['sometimes', 'boolean'],
            'academic_year_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
        ];
    }
}
