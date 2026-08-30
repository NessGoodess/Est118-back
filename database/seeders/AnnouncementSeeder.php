<?php

namespace Database\Seeders;

use App\Models\Announcement;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AnnouncementSeeder extends Seeder
{
    /**
     * Purge announcements and seed six example publications (avisos + noticias).
     */
    public function run(): void
    {
        if (! Schema::hasTable('announcements')) {
            $this->command?->warn('Tabla announcements no existe. Omite AnnouncementSeeder.');

            return;
        }

        Schema::disableForeignKeyConstraints();
        DB::table('announcements')->truncate();
        Schema::enableForeignKeyConstraints();

        $samples = [
            [
                'slug' => 'bienvenida-ciclo-escolar',
                'header' => 'Noticia',
                'title' => 'Bienvenida al nuevo ciclo escolar',
                'type' => 'Noticia',
                'important' => true,
                'header_alert_enabled' => true,
                'header_alert_label' => 'Destacado',
                'summary' => 'La comunidad de la Técnica 118 da la bienvenida a estudiantes y familias en el inicio del ciclo.',
                'content_text' => 'Iniciamos el ciclo con el compromiso de ofrecer formación académica y técnica de calidad. Les invitamos a seguir los canales oficiales de la escuela para enterarse de calendarios, avisos y actividades.',
                'content_blocks' => [
                    ['type' => 'paragraph', 'text' => 'Durante las primeras semanas se reforzarán los protocolos de ingreso, el uso de credenciales y la comunicación con tutores.'],
                    ['type' => 'list', 'items' => [
                        'Revisar el calendario oficial publicado en el portal.',
                        'Actualizar datos de contacto en contraloría si hubo cambios.',
                        'Seguir el Facebook institucional EscSecTecnica118.',
                    ]],
                    ['type' => 'paragraph', 'text' => 'Agradecemos la confianza de las familias y el esfuerzo del personal docente y administrativo.'],
                ],
                'media_src' => 'https://picsum.photos/seed/est118-welcome/1280/960',
                'days_ago' => 1,
            ],
            [
                'slug' => 'reunion-padres-familia',
                'header' => 'Aviso',
                'title' => 'Reunión de padres de familia — primer grado',
                'type' => 'Recordatorio',
                'important' => true,
                'header_alert_enabled' => true,
                'header_alert_label' => 'Importante',
                'summary' => 'Convocatoria a tutores de primer grado para conocer lineamientos y talleres.',
                'content_text' => 'Se convoca a madres, padres y tutores de primer grado a la reunión informativa en el aula magna.',
                'content_blocks' => [
                    ['type' => 'paragraph', 'text' => 'La reunión tiene como objetivo presentar el reglamento interno, el uso de talleres y los canales de comunicación con la escuela.'],
                    ['type' => 'list', 'items' => [
                        'Fecha: próximo viernes a las 9:00 h.',
                        'Lugar: aula magna.',
                        'Llevar identificación y folio de preinscripción si aplica.',
                    ]],
                ],
                'media_src' => 'https://picsum.photos/seed/est118-meeting/1280/960',
                'secondary_button_enabled' => true,
                'secondary_button_label' => 'Ver ubicación',
                'secondary_button_href' => '/#ubicacion',
                'days_ago' => 2,
            ],
            [
                'slug' => 'calendario-evaluaciones',
                'header' => 'Calendario',
                'title' => 'Calendario de evaluaciones del periodo',
                'type' => 'Informativo',
                'important' => false,
                'summary' => 'Consulta las fechas de exámenes y entregas de proyectos por grado.',
                'content_text' => 'Se publica el calendario de evaluaciones del periodo en curso. Revisa con tu grupo las fechas específicas.',
                'content_blocks' => [
                    ['type' => 'paragraph', 'text' => 'Las evaluaciones se realizarán conforme al calendario escolar. Cualquier ajuste se comunicará con anticipación por los canales oficiales.'],
                    ['type' => 'list', 'items' => [
                        'Primera semana: materias instrumentales.',
                        'Segunda semana: talleres y proyectos.',
                        'Entrega de resultados: según el cronograma de cada grado.',
                    ]],
                ],
                'media_src' => 'https://picsum.photos/seed/est118-calendar/1280/960',
                'days_ago' => 4,
            ],
            [
                'slug' => 'suspension-clases-mantenimiento',
                'header' => 'Urgente',
                'title' => 'Suspensión de clases por mantenimiento eléctrico',
                'type' => 'Urgente',
                'important' => true,
                'header_alert_enabled' => true,
                'header_alert_label' => 'Urgente',
                'summary' => 'No habrá actividades presenciales el día indicado por trabajos de mantenimiento.',
                'content_text' => 'Por trabajos de mantenimiento en la instalación eléctrica, se suspenden las clases el día señalado.',
                'content_blocks' => [
                    ['type' => 'paragraph', 'text' => 'El personal administrativo atenderá solo trámites urgentes en contraloría. Las clases se reanudarán al día siguiente en horario habitual.'],
                    ['type' => 'paragraph', 'text' => 'Agradecemos su comprensión y pedimos difundir este aviso entre las familias.'],
                ],
                'media_src' => 'https://picsum.photos/seed/est118-urgent/1280/960',
                'days_ago' => 0,
            ],
            [
                'slug' => 'feria-ciencias-talleres',
                'header' => 'Noticia',
                'title' => 'Feria de ciencias y muestra de talleres',
                'type' => 'Noticia',
                'important' => false,
                'summary' => 'Estudiantes presentarán proyectos de informática, diseño industrial y más.',
                'content_text' => 'La feria de ciencias reunirá proyectos de los distintos talleres de la escuela.',
                'content_blocks' => [
                    ['type' => 'paragraph', 'text' => 'Las familias están invitadas a recorrer los stands y conocer el trabajo de nuestras y nuestros estudiantes.'],
                    ['type' => 'list', 'items' => [
                        'Informática y sistemas.',
                        'Diseño industrial.',
                        'Confección del vestido e industria textil.',
                        'Máquinas, herramientas y sistemas de control.',
                    ]],
                    ['type' => 'youtube', 'youtubeId' => 'dQw4w9WgXcQ', 'caption' => 'Video de muestra (reemplazar por el oficial)'],
                ],
                'media_type' => 'youtube',
                'media_youtube_id' => 'dQw4w9WgXcQ',
                'media_src' => null,
                'days_ago' => 6,
            ],
            [
                'slug' => 'entrega-credenciales',
                'header' => 'Trámite',
                'title' => 'Entrega de credenciales escolares',
                'type' => 'Tarea',
                'important' => false,
                'summary' => 'Horarios para recoger credenciales en contraloría con identificación.',
                'content_text' => 'Ya se encuentran listas las credenciales del alumnado. Acude en el horario indicado.',
                'content_blocks' => [
                    ['type' => 'paragraph', 'text' => 'La entrega se realizará en contraloría. Es indispensable presentar identificación oficial del tutor o del estudiante.'],
                    ['type' => 'list', 'items' => [
                        'Horario: 7:15 a 9:30 y 10:00 a 13:30 h.',
                        'Llevar identificación oficial.',
                        'Verificar que los datos de la credencial sean correctos al recibirla.',
                    ]],
                ],
                'media_src' => 'https://picsum.photos/seed/est118-id/1280/960',
                'days_ago' => 8,
            ],
        ];

        foreach ($samples as $sample) {
            $mediaType = $sample['media_type'] ?? 'image';

            Announcement::create([
                'slug' => $sample['slug'],
                'header' => $sample['header'],
                'title' => $sample['title'],
                'header_alert_enabled' => $sample['header_alert_enabled'] ?? false,
                'header_alert_label' => $sample['header_alert_label'] ?? null,
                'content_type' => 'text',
                'content_text' => $sample['content_text'],
                'content_items' => null,
                'secondary_button_enabled' => $sample['secondary_button_enabled'] ?? false,
                'secondary_button_label' => $sample['secondary_button_label'] ?? null,
                'secondary_button_href' => $sample['secondary_button_href'] ?? null,
                'media_type' => $mediaType,
                'media_src' => $sample['media_src'] ?? null,
                'media_youtube_id' => $sample['media_youtube_id'] ?? null,
                'media_alt' => $sample['title'],
                'media_ratio' => '4/3',
                'published_at' => now()->subDays($sample['days_ago']),
                'author' => 'Escuela Secundaria Técnica No. 118',
                'type' => $sample['type'],
                'important' => $sample['important'] ?? false,
                'summary' => $sample['summary'],
                'content_blocks' => $sample['content_blocks'],
                'created_by' => null,
            ]);
        }

        $this->command?->info('Anouncements limpiados y 6 publicaciones de ejemplo sembradas.');
    }
}
