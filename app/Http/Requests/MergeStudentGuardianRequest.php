<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MergeStudentGuardianRequest extends FormRequest
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
            'draft_guardian_id' => ['required', 'integer', 'exists:guardians,id'],
            'canonical_guardian_id' => ['required', 'integer', 'exists:guardians,id', 'different:draft_guardian_id'],
            'relationship' => ['nullable', 'string', 'max:50'],
        ];
    }
}
