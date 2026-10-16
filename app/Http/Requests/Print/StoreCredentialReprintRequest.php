<?php

namespace App\Http\Requests\Print;

use App\Enums\CredentialReprintReason;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCredentialReprintRequest extends FormRequest
{
    public const RELATIONS = ['student', 'guardian', 'staff', 'other'];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'reason' => ['required', 'string', Rule::enum(CredentialReprintReason::class)],
            'notes' => [
                Rule::requiredIf(fn () => $this->input('reason') === CredentialReprintReason::Other->value),
                'nullable',
                'string',
                'max:1000',
            ],
            'requester_relation' => ['sometimes', 'string', Rule::in(self::RELATIONS)],
            'requester_name' => ['nullable', 'string', 'max:160'],
        ];
    }

    public function messages(): array
    {
        return [
            'notes.required' => 'Describe el motivo cuando eliges «Otro».',
        ];
    }
}
