<?php

namespace App\Http\Requests\Staff;

use App\Enums\StaffStatus;
use App\Http\Requests\People\PersonRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreStaffRequest extends FormRequest
{
    use PersonRules;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            ...$this->personRules(),
            ...$this->addressRules(),
            'position' => ['required', 'string', 'min:2', 'max:100'],
            'department' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(StaffStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->uppercaseFields(['curp']);
    }
}
