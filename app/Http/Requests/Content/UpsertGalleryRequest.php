<?php

namespace App\Http\Requests\Content;

use App\Models\Content\Gallery;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertGalleryRequest extends FormRequest
{
    /** Album categories offered by the admin form. */
    public const CATEGORIES = [
        'Talleres',
        'Deportes',
        'Ceremonias',
        'Excursiones',
        'Académico',
        'Comunidad',
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
        $ignoreId = $this->galleryId();

        $slugRule = ['nullable', 'string', 'max:255'];
        $slugRule[] = $ignoreId
            ? Rule::unique('galleries', 'slug')->ignore($ignoreId)
            : 'unique:galleries,slug';

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => $slugRule,
            'description' => ['nullable', 'string', 'max:1000'],
            'category' => ['required', Rule::in(self::CATEGORIES)],
            'cover_src' => ['nullable', 'string', 'max:1024'],
            'featured' => ['sometimes', 'boolean'],
            'publish_action' => ['nullable', 'in:draft,publish,schedule'],
            'published_at' => ['nullable', 'date'],

            'items' => ['required', 'array', 'min:1', 'max:120'],
            'items.*.media_src' => ['required', 'string', 'max:1024'],
            'items.*.alt' => ['required', 'string', 'max:255'],
            'items.*.caption' => ['nullable', 'string', 'max:255'],
            'items.*.ratio' => ['nullable', 'in:4/3,3/4,1/1,16/9'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Agrega al menos una foto al álbum.',
            'items.min' => 'Agrega al menos una foto al álbum.',
            'items.*.alt.required' => 'Cada foto necesita un texto alternativo.',
        ];
    }

    private function galleryId(): ?int
    {
        $gallery = $this->route('gallery');

        if ($gallery instanceof Gallery) {
            return $gallery->id;
        }

        return is_numeric($gallery) ? (int) $gallery : null;
    }
}
