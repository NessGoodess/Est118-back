<?php

namespace App\Http\Requests\School;

use App\Enums\ReEnrollmentProcessStep;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReEnrollmentPeriodRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:120'],
            'start_at' => ['sometimes', 'date'],
            'end_at' => ['sometimes', 'date'],
            'keep_current_groups' => ['sometimes', 'boolean'],
            'current_step' => ['sometimes', Rule::enum(ReEnrollmentProcessStep::class)],
        ];
    }
}
