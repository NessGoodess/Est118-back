<?php

namespace App\Http\Requests\Admission;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateAdmissionIntakeSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'score_mode' => ['sometimes', Rule::in(['exam', 'school_average', 'combined'])],
            'exam_weight' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'average_weight' => ['sometimes', 'numeric', 'min:0', 'max:1'],
            'balance_load' => ['sometimes', 'boolean'],
            'balance_scores' => ['sometimes', 'boolean'],
            'separate_same_school' => ['sometimes', Rule::in(['off', 'soft', 'hard'])],
            'separate_siblings' => ['sometimes', Rule::in(['off', 'soft', 'hard'])],
            'sibling_detection' => ['sometimes', Rule::in(['linked_only', 'guardian_curp', 'lastname_warn'])],
            'allow_convert_without_complete_docs' => ['sometimes', 'boolean'],
            'allow_convert_without_complete_data' => ['sometimes', 'boolean'],
            'allow_convert_without_payment' => ['sometimes', 'boolean'],
            'require_exam_before_convert' => ['sometimes', 'boolean'],
            'require_score_before_placement' => ['sometimes', 'boolean'],
            'allow_manual_group_change' => ['sometimes', 'boolean'],
            'late_intake_enabled' => ['sometimes', 'boolean'],
            'late_requires_manual_group' => ['sometimes', 'boolean'],
            'late_suggest_group' => ['sometimes', 'boolean'],
            'late_lock_batch_rebalance' => ['sometimes', 'boolean'],
        ];
    }
}
