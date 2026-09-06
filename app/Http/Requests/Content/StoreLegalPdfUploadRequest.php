<?php

namespace App\Http\Requests\Content;

use App\Services\Media\PublicMediaStorageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreLegalPdfUploadRequest extends FormRequest
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
            'collection' => ['required', Rule::in([PublicMediaStorageService::LEGAL_DOCUMENTS_DIR])],
            'file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'file.required' => 'Selecciona un archivo PDF.',
            'file.mimes' => 'Solo se permiten archivos PDF.',
            'file.max' => 'El PDF debe pesar 10 MB o menos.',
        ];
    }
}
