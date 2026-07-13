<?php

namespace App\Http\Requests\School;

use App\Enums\ReEnrollmentValidationStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateReEnrollmentApplicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(ReEnrollmentValidationStatus::class)],
            'passed_cycle' => ['sometimes', 'boolean'],
            'documents_complete' => ['sometimes', 'boolean'],
            'guardian_updated' => ['sometimes', 'boolean'],
            'phone_updated' => ['sometimes', 'boolean'],
            'address_updated' => ['sometimes', 'boolean'],
            'photo_updated' => ['sometimes', 'boolean'],
            'no_debts' => ['sometimes', 'boolean'],
            'comments' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'target_class_group_id' => ['sometimes', 'nullable', 'integer', 'exists:class_groups,id'],
        ];
    }
}
