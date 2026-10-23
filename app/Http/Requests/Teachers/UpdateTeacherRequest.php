<?php

namespace App\Http\Requests\Teachers;

use App\Enums\TeacherStatus;
use App\Http\Requests\People\PersonRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTeacherRequest extends FormRequest
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
            ...$this->insuranceRules('personal', false),
            'address' => ['sometimes', 'array'],
            ...$this->addressRules('address', false),
            ...$this->emergencyContactRules(),
            'job' => ['sometimes', 'array'],
            'job.employee' => ['sometimes', 'nullable', 'string', 'max:100'],
            'job.classroom' => ['sometimes', 'nullable', 'string', 'max:100'],
            'job.position' => ['sometimes', 'nullable', 'string', 'max:100'],
            'job.department' => ['sometimes', 'nullable', 'string', 'max:100'],
            'job.status' => ['sometimes', Rule::enum(TeacherStatus::class)],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->uppercaseFields(['personal.curp']);
    }
}
