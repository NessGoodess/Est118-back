<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignFirstGradeGroupsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer', 'min:1'],
            'score_source' => ['sometimes', 'nullable', Rule::in(['school_average', 'admission_exam', 'combined'])],
            'dry_run' => ['sometimes', 'boolean'],
            'overrides' => ['sometimes', 'array'],
            'overrides.*.enrollment_id' => ['required_with:overrides', 'integer', 'min:1'],
            'overrides.*.class_group_id' => ['required_with:overrides', 'integer', 'min:1'],
        ];
    }
}
