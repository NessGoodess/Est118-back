<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreNewIntakeRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null && (
            $user->can('edit students')
            || $user->can('manage re-enrollment')
            || $user->can('create pre-enrollments')
            || $user->can('edit admission enrollment')
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $name = ['required', 'string', 'min:2', 'max:50'];
        $curp = ['required', 'string', 'size:18', 'regex:/^[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[A-Z0-9]{2}$/'];

        return [
            'first_name' => $name,
            'last_name' => $name,
            'second_last_name' => ['nullable', 'string', 'max:50'],
            'curp' => $curp,
            'birth_date' => ['required', 'date'],
            'gender' => ['required', 'in:M,F,O'],
            'phone' => ['nullable', 'digits:10'],
            'email' => ['nullable', 'email', 'max:255'],
            'contact_email' => ['required', 'email', 'max:255'],
            'place_of_birth' => ['required', 'string', 'max:100'],
            'previous_school' => ['required', 'string', 'max:200'],
            'current_average' => ['required', 'numeric', 'between:6,10'],
            'sibling_ids' => ['nullable', 'array'],
            'sibling_ids.*' => ['integer', 'exists:students,id'],
            'school_voucher_folio' => ['nullable', 'string', 'max:50'],
            'street_type' => ['required', 'string', 'max:40'],
            'street_name' => ['required', 'string', 'min:2', 'max:100'],
            'house_number' => ['required', 'string', 'max:10'],
            'unit_number' => ['nullable', 'string', 'max:10'],
            'neighborhood_type' => ['required', 'string', 'max:40'],
            'neighborhood_name' => ['required', 'string', 'min:2', 'max:100'],
            'postal_code' => ['required', 'digits:5'],
            'city' => ['required', 'string', 'max:100'],
            'state' => ['required', 'string', 'max:100'],
            'guardian_first_name' => $name,
            'guardian_last_name' => $name,
            'guardian_second_last_name' => ['nullable', 'string', 'max:50'],
            'guardian_curp' => ['nullable', ...array_slice($curp, 1)],
            'guardian_birth_date' => ['nullable', 'date'],
            'guardian_gender' => ['nullable', 'in:M,F,O'],
            'guardian_phone' => ['required', 'digits:10'],
            'guardian_relationship' => ['required', 'string', 'min:2', 'max:50'],
            'grade_level_id' => ['required', 'integer', 'exists:grade_levels,id'],
            'class_group_id' => ['required', 'integer', 'exists:class_groups,id'],
            'workshop_id' => ['required', 'integer', 'exists:workshops,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $upper = ['curp', 'guardian_curp'];
        $merged = [];
        foreach ($upper as $field) {
            if ($this->filled($field)) {
                $merged[$field] = strtoupper(trim((string) $this->input($field)));
            }
        }
        if ($merged !== []) {
            $this->merge($merged);
        }
    }
}
