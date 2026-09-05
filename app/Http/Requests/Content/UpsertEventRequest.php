<?php

namespace App\Http\Requests\Content;

use App\Models\Content\Event;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class UpsertEventRequest extends FormRequest
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
        $ignoreId = $this->eventId();

        $slugRule = ['nullable', 'string', 'max:255'];
        $slugRule[] = $ignoreId
            ? Rule::unique('events', 'slug')->ignore($ignoreId)
            : 'unique:events,slug';

        return [
            'title' => ['required', 'string', 'max:255'],
            'slug' => $slugRule,
            'type' => ['required', Rule::in(Event::TYPES)],
            'summary' => ['nullable', 'string', 'max:500'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['nullable', 'date'],
            'location' => ['nullable', 'string', 'max:255'],
            'cover_src' => ['nullable', 'string', 'max:1024'],
            'important' => ['sometimes', 'boolean'],
            'gallery_id' => ['nullable', 'integer', 'exists:galleries,id'],
            'publish_action' => ['nullable', 'in:draft,publish,schedule'],
            'published_at' => ['nullable', 'date'],

            'content_blocks' => ['nullable', 'array'],
            'content_blocks.*.type' => [
                'required_with:content_blocks',
                'in:paragraph,list,image,video,youtube,gallery,gallery_ref',
            ],
            'content_blocks.*.text' => ['nullable', 'string'],
            'content_blocks.*.items' => ['nullable', 'array'],
            'content_blocks.*.items.*' => ['string'],
            'content_blocks.*.src' => ['nullable', 'string', 'max:1024'],
            'content_blocks.*.alt' => ['nullable', 'string', 'max:255'],
            'content_blocks.*.caption' => ['nullable', 'string', 'max:255'],
            'content_blocks.*.youtubeId' => ['nullable', 'string', 'max:64'],
            'content_blocks.*.layout' => ['nullable', 'in:carousel,grid'],
            'content_blocks.*.title' => ['nullable', 'string', 'max:255'],
            'content_blocks.*.galleryId' => ['nullable', 'integer', 'exists:galleries,id'],
            'content_blocks.*.albumHref' => ['nullable', 'string', 'max:1024'],
            'content_blocks.*.images' => ['nullable', 'array', 'max:40'],
            'content_blocks.*.images.*.src' => ['required_with:content_blocks.*.images', 'string', 'max:1024'],
            'content_blocks.*.images.*.alt' => ['nullable', 'string', 'max:255'],
            'content_blocks.*.images.*.caption' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $data = $validator->getData();
            $start = $data['starts_at'] ?? null;
            $end = $data['ends_at'] ?? null;

            if ($start && $end && strtotime((string) $end) < strtotime((string) $start)) {
                $validator->errors()->add('ends_at', 'La fecha de fin no puede ser anterior al inicio.');
            }
        });
    }

    private function eventId(): ?int
    {
        $event = $this->route('event');

        if ($event instanceof Event) {
            return $event->id;
        }

        return is_numeric($event) ? (int) $event : null;
    }
}
