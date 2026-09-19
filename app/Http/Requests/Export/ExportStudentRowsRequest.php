<?php

namespace App\Http\Requests\Export;

use Illuminate\Foundation\Http\FormRequest;

class ExportStudentRowsRequest extends FormRequest
{
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
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['required', 'integer'],
        ];
    }
}
