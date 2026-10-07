<?php

namespace App\Http\Requests\Teachers;

use Illuminate\Foundation\Http\FormRequest;

class SyncTeacherClassesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'class_ids' => ['present', 'array'],
            'class_ids.*' => ['integer', 'exists:school_classes,id'],
        ];
    }
}
