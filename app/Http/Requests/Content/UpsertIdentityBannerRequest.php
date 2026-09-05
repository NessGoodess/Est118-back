<?php

namespace App\Http\Requests\Content;

use App\Models\Content\IdentityBanner;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpsertIdentityBannerRequest extends FormRequest
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
        $ignoreId = $this->bannerId();

        $slugRule = ['nullable', 'string', 'max:255'];
        $slugRule[] = $ignoreId
            ? Rule::unique('identity_banners', 'slug')->ignore($ignoreId)
            : 'unique:identity_banners,slug';

        return [
            'slug' => $slugRule,
            'src' => ['required', 'string', 'max:1024'],
            'alt' => ['required', 'string', 'max:255'],

            'show_copy' => ['sometimes', 'boolean'],
            'eyebrow' => ['nullable', 'string', 'max:120', 'required_if:show_copy,true,1'],
            'title' => ['nullable', 'string', 'max:180', 'required_if:show_copy,true,1'],
            'description' => ['nullable', 'string', 'max:500', 'required_if:show_copy,true,1'],

            'show_cta' => ['sometimes', 'boolean'],
            'href' => ['nullable', 'string', 'max:1024', 'required_if:show_cta,true,1'],
            'cta' => ['nullable', 'string', 'max:80', 'required_if:show_cta,true,1'],

            'sort_order' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'publish_action' => ['nullable', 'in:draft,publish,schedule'],
            'published_at' => ['nullable', 'date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'src.required' => 'Sube o indica la imagen del banner.',
            'alt.required' => 'Describe la imagen para accesibilidad.',
            'eyebrow.required_if' => 'El antetítulo es obligatorio cuando hay texto.',
            'title.required_if' => 'El título es obligatorio cuando hay texto.',
            'description.required_if' => 'La descripción es obligatoria cuando hay texto.',
            'href.required_if' => 'Indica el enlace del botón.',
            'cta.required_if' => 'Indica el texto del botón.',
        ];
    }

    private function bannerId(): ?int
    {
        $banner = $this->route('identity_banner') ?? $this->route('identityBanner');

        if ($banner instanceof IdentityBanner) {
            return $banner->id;
        }

        return is_numeric($banner) ? (int) $banner : null;
    }
}
