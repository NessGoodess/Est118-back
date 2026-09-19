<?php

namespace App\Http\Requests\Export;

use Illuminate\Foundation\Http\FormRequest;

class StoreExportTemplateRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'context' => ['required', 'string', 'max:64', 'regex:/^[a-z0-9_-]+$/'],
            'file' => ['required', 'file', 'mimes:xlsx', 'max:10240'],
            'is_default' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.mimes' => 'Solo se permiten archivos Excel (.xlsx).',
            'file.max' => 'La plantilla no puede superar 10 MB.',
        ];
    }
}
