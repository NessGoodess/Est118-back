<?php

namespace App\Http\Requests\People;

trait PersonRules
{
    /**
     * @return array<string, mixed>
     */
    protected function personRules(string $prefix = '', bool $required = true): array
    {
        $key = $prefix === '' ? '' : rtrim($prefix, '.').'.';
        $presence = $required ? 'required' : 'sometimes';
        $name = [$presence, 'string', 'min:2', 'max:50'];
        $curp = [$presence, 'string', 'size:18', 'regex:/^[A-Z]{4}[0-9]{6}[HM][A-Z]{5}[A-Z0-9]{2}$/'];

        return [
            $key.'first_name' => $name,
            $key.'last_name' => $name,
            $key.'second_last_name' => ['nullable', 'string', 'max:50'],
            $key.'curp' => $curp,
            $key.'birth_date' => [$presence, 'date'],
            $key.'gender' => [$presence, 'in:M,F,O'],
            $key.'phone' => ['nullable', 'digits:10'],
            $key.'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function addressRules(string $prefix = '', bool $required = true): array
    {
        $key = $prefix === '' ? '' : rtrim($prefix, '.').'.';
        $presence = $required ? 'required' : 'sometimes';

        return [
            $key.'street_type' => [$presence, 'string', 'max:40'],
            $key.'street_name' => [$presence, 'string', 'min:2', 'max:100'],
            $key.'house_number' => [$presence, 'string', 'max:10'],
            $key.'unit_number' => ['nullable', 'string', 'max:10'],
            $key.'neighborhood_type' => [$presence, 'string', 'max:40'],
            $key.'neighborhood_name' => [$presence, 'string', 'min:2', 'max:100'],
            $key.'postal_code' => [$presence, 'digits:5'],
            $key.'city' => [$presence, 'string', 'max:100'],
            $key.'state' => [$presence, 'string', 'max:100'],
        ];
    }

    /**
     * @param  array<int, string>  $fields
     */
    protected function uppercaseFields(array $fields): void
    {
        $merged = [];
        foreach ($fields as $field) {
            if ($this->filled($field)) {
                $merged[$field] = strtoupper(trim((string) $this->input($field)));
            }
        }
        if ($merged !== []) {
            $this->merge($merged);
        }
    }
}
