<?php

namespace App\Http\Requests\Content;

use App\Models\Content\LegalDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpsertLegalDocumentRequest extends FormRequest
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
            'title' => ['required', 'string', 'max:180'],
            'src' => ['nullable', 'string', 'max:1024'],
            'original_name' => ['nullable', 'string', 'max:255'],
            'publish_action' => ['nullable', 'in:draft,publish'],
            'published_at' => ['nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->input('publish_action') !== 'publish') {
                return;
            }

            $src = trim((string) $this->input('src', ''));
            if ($src === '') {
                $validator->errors()->add('src', 'Sube un PDF antes de publicar.');
            }
        });
    }

    public function documentType(): string
    {
        $type = (string) $this->route('type');

        if (! in_array($type, LegalDocument::TYPES, true)) {
            abort(404);
        }

        return $type;
    }
}
