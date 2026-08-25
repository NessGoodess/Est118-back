<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route already requires permission:edit students
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'profile' => ['sometimes', 'array'],
            'profile.first_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.last_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'profile.national_id' => ['sometimes', 'nullable', 'string', 'max:18'],
            'profile.birth_date' => ['sometimes', 'nullable', 'date'],
            'profile.gender' => ['sometimes', 'nullable', Rule::in(['M', 'F', 'O'])],
            'profile.phone_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'profile.phone_second_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'profile.email' => ['sometimes', 'nullable', 'email', 'max:255'],

            'address' => ['sometimes', 'array'],
            'address.street_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address.street_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'address.house_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address.unit_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address.apartament_number' => ['sometimes', 'nullable', 'string', 'max:30'],
            'address.neighborhood_type' => ['sometimes', 'nullable', 'string', 'max:50'],
            'address.neighborhood_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'address.postal_code' => ['sometimes', 'nullable', 'string', 'max:10'],
            'address.city' => ['sometimes', 'nullable', 'string', 'max:100'],
            'address.state' => ['sometimes', 'nullable', 'string', 'max:100'],
        ];
    }
}
