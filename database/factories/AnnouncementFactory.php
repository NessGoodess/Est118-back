<?php

namespace Database\Factories;

use App\Models\Announcement;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Announcement>
 */
class AnnouncementFactory extends Factory
{
    protected $model = Announcement::class;

    public function definition(): array
    {
        $title = fake()->sentence(6);

        return [
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(4)),
            'header' => 'Comunicado',
            'title' => rtrim($title, '.'),
            'header_alert_enabled' => false,
            'header_alert_label' => null,
            'content_type' => 'text',
            'content_text' => fake()->paragraph(3),
            'content_items' => null,
            'secondary_button_enabled' => false,
            'secondary_button_label' => null,
            'secondary_button_href' => null,
            'media_type' => 'image',
            'media_src' => 'https://picsum.photos/seed/'.Str::random(8).'/1280/960',
            'media_youtube_id' => null,
            'media_alt' => 'Imagen del aviso',
            'media_ratio' => '4/3',
            'published_at' => now()->subDays(fake()->numberBetween(0, 20)),
            'author' => 'Dirección escolar',
            'type' => fake()->randomElement(['Informativo', 'Urgente', 'Recordatorio', 'Tarea', 'General', 'Noticia']),
            'important' => fake()->boolean(20),
            'summary' => fake()->sentence(12),
            'content_blocks' => [
                ['type' => 'paragraph', 'text' => fake()->paragraph(4)],
                ['type' => 'list', 'items' => [
                    fake()->sentence(8),
                    fake()->sentence(7),
                    fake()->sentence(9),
                ]],
            ],
            'created_by' => null,
        ];
    }
}
