<?php

namespace App\Http\Requests\School;

use Illuminate\Foundation\Http\FormRequest;

class BulkDecideReEnrollmentApplicationsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
            'is_approved' => ['required_without:reject', 'prohibited_if:reject,true', 'boolean'],
            'reject' => ['sometimes', 'boolean'],
        ];
    }
}
