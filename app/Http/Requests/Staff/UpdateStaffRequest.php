<?php

namespace App\Http\Requests\Staff;

use App\Enums\StaffStatus;
use App\Http\Requests\People\PersonRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateStaffRequest extends FormRequest
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
            'personal' => ['sometimes', 'array'],
            ...$this->personRules('personal', false),
            'address' => ['sometimes', 'array'],
            ...$this->addressRules('address', false),
            'job' => ['sometimes', 'array'],
            'job.position' => ['sometimes', 'string', 'min:2', 'max:100'],
            'job.department' => ['sometimes', 'nullable', 'string', 'max:100'],
            'job.status' => ['sometimes', Rule::enum(StaffStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->uppercaseFields(['personal.curp']);
    }
}
