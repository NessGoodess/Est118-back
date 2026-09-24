<?php

namespace App\Http\Requests\Print;

use Illuminate\Foundation\Http\FormRequest;

class StorePrintJobsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', 'distinct', 'exists:students,id'],
            'printer_id' => ['sometimes', 'string', 'max:64'],
            'template_key' => ['sometimes', 'string', 'max:64'],
            'side_mode' => ['sometimes', 'string', 'in:front,back'],
        ];
    }
}
