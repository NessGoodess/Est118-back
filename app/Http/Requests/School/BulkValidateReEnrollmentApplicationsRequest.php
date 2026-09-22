<?php

namespace App\Http\Requests\School;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkValidateReEnrollmentApplicationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'scopes' => ['required', 'array', 'min:1'],
            'scopes.*' => ['required', 'string', Rule::in(['debts', 'data_update', 'documents'])],
            'grade' => ['sometimes', 'nullable', 'string', 'max:50'],
            'group' => ['sometimes', 'nullable', 'string', 'max:50'],
        ];
    }
}
