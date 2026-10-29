<?php

namespace App\Http\Requests;

use App\Http\Requests\People\PersonRules;
use App\Support\Curp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentGuardianRequest extends FormRequest
{
    use PersonRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $curp = Curp::normalize($this->input('national_id'));
        $email = trim((string) $this->input('email', ''));
        $phone = trim((string) $this->input('phone', ''));

        $merge = [
            'national_id' => $curp === '' ? null : $curp,
            'email' => $email === '' ? null : $email,
            'phone' => $phone === '' ? null : $phone,
        ];

        // Only normalize when the client sends it, so omitted field keeps existing maternal.
        if ($this->exists('second_last_name')) {
            $second = trim((string) $this->input('second_last_name'));
            $merge['second_last_name'] = $second === '' ? null : $second;
        }

        $this->merge($merge);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $needsAddress = $this->input('address_mode') === 'new';

        // CURP uniqueness is resolved in GuardianCurpMergeService (409 merge conflict vs hard error).
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'second_last_name' => ['nullable', 'string', 'max:100'],
            'national_id' => [
                'nullable',
                'string',
                'size:18',
                'regex:'.Curp::PATTERN,
            ],
            'relationship' => ['nullable', 'string', 'max:50'],
            'is_primary' => ['sometimes', 'boolean'],
            'is_secondary' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
            'address_mode' => ['sometimes', 'nullable', Rule::in(['student', 'new'])],
            ...$this->addressRules('address', $needsAddress),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'national_id.regex' => 'La CURP del tutor no es válida.',
            'national_id.size' => 'La CURP del tutor debe tener 18 caracteres.',
            'address_mode.in' => 'Selecciona un domicilio válido.',
        ];
    }
}
