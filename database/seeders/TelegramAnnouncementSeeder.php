<?php

namespace Database\Seeders;

use App\Models\Announcement;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Schema;

/**
 * Seeds / updates the Telegram bot announcement (idempotent by slug).
 * Does not truncate other announcements.
 */
class TelegramAnnouncementSeeder extends Seeder
{
    public function run(): void
    {
        if (! Schema::hasTable('announcements')) {
            $this->command?->warn('Tabla announcements no existe. Omite TelegramAnnouncementSeeder.');

            return;
        }

        Announcement::updateOrCreate(
            ['slug' => 'bot-telegram-notificaciones'],
            [
                'header' => 'Bot de Telegram EST118',
                'title' => 'Recibe notificaciones automáticas cuando tu hijo registre entrada o salida en la escuela.',
                'header_alert_enabled' => true,
                'header_alert_label' => 'Nuevo servicio disponible',
                'content_type' => 'list',
                'content_text' => null,
                'content_items' => [
                    'Notificaciones en tiempo real',
                    'Vinculación con CURP',
                    'Activación en minutos',
                ],
                'secondary_button_enabled' => true,
                'secondary_button_label' => 'Instrucciones',
                'secondary_button_href' => '/instrucciones',
                'media_type' => 'youtube',
                'media_src' => null,
                'media_youtube_id' => 'fYrk3yMz7Ro',
                'media_alt' => 'Bot de Telegram EST118',
                'media_ratio' => '4/3',
                'published_at' => now()->subDays(3),
                'author' => 'Sistemas',
                'type' => 'Informativo',
                'important' => true,
                'summary' => 'Bot de Telegram para notificaciones de entrada y salida.',
                'content_blocks' => [
                    [
                        'type' => 'paragraph',
                        'text' => 'Puedes recibir notificaciones automáticas cuando tu hijo registre entrada o salida en la escuela, mediante nuestro bot de Telegram. La vinculación es segura y se realiza con CURP.',
                    ],
                    [
                        'type' => 'paragraph',
                        'text' => 'Si deseas ver un tutorial en video, puedes consultar el siguiente enlace o escanear el código QR en recepción.',
                    ],
                    [
                        'type' => 'youtube',
                        'youtubeId' => 'fYrk3yMz7Ro',
                        'caption' => 'Tutorial de activación del bot de Telegram',
                    ],
                ],
                'created_by' => null,
            ]
        );

        $this->command?->info('Aviso de Telegram sembrado/actualizado (slug: bot-telegram-notificaciones).');
    }
}
