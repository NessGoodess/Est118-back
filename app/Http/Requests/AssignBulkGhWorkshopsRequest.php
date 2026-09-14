<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AssignBulkGhWorkshopsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer', 'min:1'],
            'dry_run' => ['sometimes', 'boolean'],
            'force' => ['sometimes', 'boolean'],
        ];
    }
}
