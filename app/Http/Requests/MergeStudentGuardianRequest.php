<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            'draft_guardian_id' => ['nullable', 'integer', 'exists:guardians,id'],
            'canonical_guardian_id' => ['required', 'integer', 'exists:guardians,id'],
            'survivor' => ['nullable', Rule::in(['draft', 'canonical'])],
            'field_sources' => ['nullable', 'array'],
            'field_sources.name' => ['nullable', Rule::in(['draft', 'canonical'])],
            'field_sources.phone' => ['nullable', Rule::in(['draft', 'canonical'])],
            'field_sources.email' => ['nullable', Rule::in(['draft', 'canonical'])],
            'field_sources.address' => ['nullable', Rule::in(['draft', 'canonical', 'none'])],
            'relationship' => ['nullable', 'string', 'max:50'],
            'pending' => ['nullable', 'array'],
            'pending.first_name' => ['nullable', 'string', 'max:100'],
            'pending.last_name' => ['nullable', 'string', 'max:100'],
            'pending.second_last_name' => ['nullable', 'string', 'max:100'],
            'pending.phone' => ['nullable', 'string', 'max:30'],
            'pending.email' => ['nullable', 'email', 'max:255'],
            'pending.relationship' => ['nullable', 'string', 'max:50'],
            'pending.curp' => ['nullable', 'string', 'size:18'],
        ];
    }
}
