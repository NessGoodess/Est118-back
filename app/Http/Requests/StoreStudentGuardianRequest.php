<?php

namespace App\Http\Requests;

use App\Http\Requests\People\PersonRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStudentGuardianRequest extends FormRequest
{
    use PersonRules;

    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->uppercaseFields(['curp']);

        $merged = [];
        foreach (['curp', 'phone', 'email', 'relationship', 'birth_date', 'second_last_name', 'gender'] as $field) {
            if (! $this->filled($field)) {
                $merged[$field] = null;
            }
        }
        if ($merged !== []) {
            $this->merge($merged);
        }
    }

    /**
     * Only first and last name are required; the rest matches nullable profile columns.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $needsAddress = $this->input('address_mode') === 'new';

        return [
            'first_name' => ['required', 'string', 'min:2', 'max:50'],
            'last_name' => ['required', 'string', 'min:2', 'max:50'],
            'second_last_name' => ['nullable', 'string', 'max:50'],
            'curp' => ['nullable', 'string', 'size:18', 'regex:/^[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[A-Z0-9]{2}$/'],
            'birth_date' => ['nullable', 'date'],
            'gender' => ['nullable', 'in:M,F,O'],
            'phone' => ['nullable', 'digits:10'],
            'email' => ['nullable', 'email', 'max:255'],
            'relationship' => ['nullable', 'string', 'min:2', 'max:50'],
            'is_primary' => ['sometimes', 'boolean'],
            'address_mode' => ['required', Rule::in(['student', 'new'])],
            ...$this->addressRules('address', $needsAddress),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'first_name.required' => 'El nombre del tutor es requerido.',
            'last_name.required' => 'El apellido paterno del tutor es requerido.',
            'curp.regex' => 'La CURP del tutor no es válida.',
            'curp.size' => 'La CURP del tutor debe tener 18 caracteres.',
            'address_mode.in' => 'Selecciona un domicilio válido.',
        ];
    }
}
