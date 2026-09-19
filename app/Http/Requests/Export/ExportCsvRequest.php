<?php

namespace App\Http\Requests\Export;

use Illuminate\Foundation\Http\FormRequest;

class ExportCsvRequest extends FormRequest
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
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['required', 'string', 'max:64'],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*' => ['required', 'array'],
        ];
    }
}
