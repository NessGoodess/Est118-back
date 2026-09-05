<?php

namespace App\Http\Requests;

use App\Models\Announcement;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertAnnouncementRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        // FormData may send content_blocks as a JSON string.
        if (is_string($this->input('content_blocks'))) {
            $decoded = json_decode($this->input('content_blocks'), true);
            $this->merge([
                'content_blocks' => is_array($decoded) ? $decoded : null,
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $ignoreId = $this->announcementId();

        $slugRule = ['nullable', 'string', 'max:255'];
        $slugRule[] = $ignoreId
            ? Rule::unique('announcements', 'slug')->ignore($ignoreId)
            : 'unique:announcements,slug';

        return [
            'title'                    => ['required', 'string', 'max:255'],
            'header'                   => ['nullable', 'string', 'max:255'],
            'slug'                     => $slugRule,
            'header_alert_enabled'     => ['sometimes', 'boolean'],
            'header_alert_label'       => ['nullable', 'string', 'max:255'],
            'content_type'             => ['nullable', 'in:text,list'],
            'content_text'             => ['nullable', 'string'],
            'content_items'            => ['nullable', 'array'],
            'content_items.*'          => ['string'],
            'publish_action'           => ['nullable', 'in:draft,publish,schedule'],
            'primary_button_label'     => ['nullable', 'string', 'max:255'],
            'primary_button_href'      => ['nullable', 'string', 'max:1024'],
            'primary_button_action'    => ['nullable', 'string', 'max:255'],
            'secondary_button_enabled' => ['sometimes', 'boolean'],
            'secondary_button_label'   => ['nullable', 'string', 'max:255'],
            'secondary_button_href'    => ['nullable', 'string', 'max:1024'],
            'media_type'               => ['required', 'in:image,video,youtube,facebook'],
            'media_file'               => ['nullable', 'file', 'max:51200'], // 50 MB
            'media_src'                => ['nullable', 'string', 'max:1024'],
            'media_youtube_id'         => ['nullable', 'string', 'max:255'],
            'media_alt'                => ['nullable', 'string', 'max:255'],
            'media_ratio'              => ['nullable', 'in:4/3,3/4,4/4'],
            'media_position'           => ['nullable', 'in:left,right'],
            'published_at'             => ['nullable', 'date'],
            'author'                   => ['nullable', 'string', 'max:255'],
            'type'                     => ['required', 'in:Informativo,Urgente,Recordatorio,Tarea,General,Noticia'],
            'important'                => ['sometimes', 'boolean'],
            'summary'                  => ['nullable', 'string', 'max:500'],
            'content_blocks'           => ['nullable', 'array'],
            'content_blocks.*.type'    => ['required_with:content_blocks', 'in:paragraph,list,image,video,youtube,gallery,gallery_ref'],
            'content_blocks.*.text'    => ['nullable', 'string'],
            'content_blocks.*.items'   => ['nullable', 'array'],
            'content_blocks.*.items.*' => ['string'],
            'content_blocks.*.src'     => ['nullable', 'string', 'max:1024'],
            'content_blocks.*.alt'     => ['nullable', 'string', 'max:255'],
            'content_blocks.*.caption' => ['nullable', 'string', 'max:255'],
            'content_blocks.*.youtubeId' => ['nullable', 'string', 'max:64'],
            'content_blocks.*.layout'  => ['nullable', 'in:carousel,grid'],
            'content_blocks.*.title'   => ['nullable', 'string', 'max:255'],
            'content_blocks.*.galleryId' => ['nullable', 'integer', 'exists:galleries,id'],
            'content_blocks.*.albumHref' => ['nullable', 'string', 'max:1024'],
            'content_blocks.*.images'  => ['nullable', 'array', 'max:40'],
            'content_blocks.*.images.*.src'     => ['required_with:content_blocks.*.images', 'string', 'max:1024'],
            'content_blocks.*.images.*.alt'     => ['nullable', 'string', 'max:255'],
            'content_blocks.*.images.*.caption' => ['nullable', 'string', 'max:255'],
            'facebook_post_url'        => [
                'nullable',
                'string',
                'max:1024',
                function (string $attribute, mixed $value, \Closure $fail): void {
                    if ($value === null || $value === '') {
                        return;
                    }
                    if (! is_string($value)) {
                        $fail('La URL de Facebook no es válida.');

                        return;
                    }
                    $host = strtolower((string) parse_url($value, PHP_URL_HOST));
                    $host = preg_replace('/^www\./', '', $host) ?? '';
                    $ok = $host === 'facebook.com'
                        || str_ends_with($host, '.facebook.com')
                        || $host === 'fb.watch'
                        || $host === 'fb.com'
                        || str_ends_with($host, '.fb.com');
                    if (! $ok) {
                        $fail('La URL debe ser de un post público de Facebook.');
                    }
                },
            ],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $validator->getData();
            $isFacebook = ($data['media_type'] ?? null) === 'facebook';

            if ($isFacebook) {
                if (blank($data['facebook_post_url'] ?? null)) {
                    $validator->errors()->add('facebook_post_url', 'Pega la URL del post de Facebook.');
                }

                return;
            }

            if (blank($data['media_alt'] ?? null)) {
                $validator->errors()->add('media_alt', 'El texto alternativo es requerido.');
            }
            if (blank($data['media_ratio'] ?? null)) {
                $validator->errors()->add('media_ratio', 'La proporción de media es requerida.');
            }
            if (blank($data['summary'] ?? null) || mb_strlen(trim((string) ($data['summary'] ?? ''))) < 10) {
                $validator->errors()->add('summary', 'El resumen debe tener al menos 10 caracteres.');
            }
        });
    }

    /**
     * Normalized payload ready for create/update (defaults for Facebook media).
     *
     * @return array<string, mixed>
     */
    public function validatedPayload(): array
    {
        $validated = $this->validated();

        if (array_key_exists('facebook_post_url', $validated) && blank($validated['facebook_post_url'])) {
            $validated['facebook_post_url'] = null;
        }

        $isFacebook = ($validated['media_type'] ?? null) === 'facebook';

        if ($isFacebook) {
            $validated['media_src'] = null;
            $validated['media_youtube_id'] = null;
            $validated['media_alt'] = filled($validated['media_alt'] ?? null)
                ? $validated['media_alt']
                : 'Publicación de Facebook';
            $validated['media_ratio'] = $validated['media_ratio'] ?? '4/3';
            $validated['media_position'] = $validated['media_position'] ?? 'right';

            if (blank($validated['summary'] ?? null)) {
                $validated['summary'] = $validated['title'];
            }
        } else {
            $validated['facebook_post_url'] = null;
        }

        return $validated;
    }

    private function announcementId(): ?int
    {
        $announcement = $this->route('announcement');

        if ($announcement instanceof Announcement) {
            return $announcement->id;
        }

        if (is_numeric($announcement)) {
            return (int) $announcement;
        }

        return null;
    }
}
