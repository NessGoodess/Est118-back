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

        $curp = trim((string) $this->input('curp', ''));
        if ($curp === '') {
            $this->merge(['curp' => null]);
        }
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $needsAddress = $this->input('address_mode') === 'new';

        return [
            ...$this->personRules(),
            'curp' => ['nullable', 'string', 'size:18', 'regex:/^[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[A-Z0-9]{2}$/'],
            'phone' => ['required', 'digits:10'],
            'email' => ['required', 'email', 'max:255'],
            'relationship' => ['required', 'string', 'min:2', 'max:50'],
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
            'curp.regex' => 'La CURP del tutor no es válida.',
            'curp.size' => 'La CURP del tutor debe tener 18 caracteres.',
            'phone.required' => 'El teléfono del tutor es requerido.',
            'email.required' => 'El correo del tutor es requerido.',
            'relationship.required' => 'El parentesco es requerido.',
            'address_mode.in' => 'Selecciona un domicilio válido.',
        ];
    }
}
