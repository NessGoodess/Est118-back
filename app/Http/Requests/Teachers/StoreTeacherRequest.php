<?php

namespace App\Http\Requests\Teachers;

use App\Enums\TeacherStatus;
use App\Http\Requests\People\PersonRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTeacherRequest extends FormRequest
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
            'employee' => ['nullable', 'string', 'max:100'],
            'classroom' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', Rule::enum(TeacherStatus::class)],
            'class_ids' => ['nullable', 'array'],
            'class_ids.*' => ['integer', 'exists:school_classes,id'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->uppercaseFields(['curp']);
    }
}
