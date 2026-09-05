<?php

namespace App\Http\Requests\Content;

use App\Services\Media\PublicMediaStorageService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreMediaUploadRequest extends FormRequest
{
    /** Directories a client is allowed to upload into. */
    public const ALLOWED_COLLECTIONS = [
        PublicMediaStorageService::ANNOUNCEMENTS_DIR,
        PublicMediaStorageService::GALLERIES_DIR,
        PublicMediaStorageService::EVENTS_DIR,
    ];

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
            'collection' => ['required', Rule::in(self::ALLOWED_COLLECTIONS)],
            'files' => ['required', 'array', 'min:1', 'max:20'],
            'files.*' => ['file', 'image', 'mimes:png,jpg,jpeg,webp,gif', 'max:5120'], // 5 MB
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'files.max' => 'Puedes subir hasta 20 imágenes por lote.',
            'files.*.max' => 'Cada imagen debe pesar 5 MB o menos.',
            'files.*.image' => 'Solo se permiten imágenes (PNG, JPG, WebP o GIF).',
        ];
    }

    public function collection(): string
    {
        return (string) $this->validated()['collection'];
    }
}
