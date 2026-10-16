<?php

namespace App\Http\Requests;

use App\Http\Requests\People\PersonRules;
use Illuminate\Foundation\Http\FormRequest;

class UpdateStudentRequest extends FormRequest
{
    use PersonRules;

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
            ...$this->personRules('profile', false),
            'profile.phone_second_number' => ['sometimes', 'nullable', 'digits:10'],

            'address' => ['sometimes', 'array'],
            ...$this->addressRules('address', false),
        ];
    }

    /**
     * Older clients send the raw column names (national_id, phone_number, apartament_number).
     */
    protected function prepareForValidation(): void
    {
        $profile = $this->input('profile');
        if (is_array($profile)) {
            $profile = $this->renameKey($profile, 'national_id', 'curp');
            $profile = $this->renameKey($profile, 'phone_number', 'phone');
            if (isset($profile['curp']) && is_string($profile['curp'])) {
                $profile['curp'] = strtoupper(trim($profile['curp']));
            }
            $this->merge(['profile' => $profile]);
        }

        $address = $this->input('address');
        if (is_array($address)) {
            $this->merge(['address' => $this->renameKey($address, 'apartament_number', 'unit_number')]);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function renameKey(array $data, string $from, string $to): array
    {
        if (array_key_exists($from, $data) && ! array_key_exists($to, $data)) {
            $data[$to] = $data[$from];
        }
        unset($data[$from]);

        return $data;
    }
}
