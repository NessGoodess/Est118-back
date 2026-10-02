<?php

namespace App\Http\Requests;

use App\Support\Curp;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentGuardianRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $curp = Curp::normalize($this->input('national_id'));
        $email = trim((string) $this->input('email', ''));
        $phone = trim((string) $this->input('phone', ''));

        $this->merge([
            'national_id' => $curp === '' ? null : $curp,
            'email' => $email === '' ? null : $email,
            'phone' => $phone === '' ? null : $phone,
        ]);
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $guardian = $this->route('guardian');
        $profileId = is_object($guardian) ? $guardian->profile_id : null;

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'national_id' => [
                'nullable',
                'string',
                'size:18',
                'regex:'.Curp::PATTERN,
                Rule::unique('profiles', 'national_id')->ignore($profileId),
            ],
            'relationship' => ['required', 'string', 'min:2', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30'],
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
            'national_id.unique' => 'Esa CURP ya está registrada.',
        ];
    }
}
