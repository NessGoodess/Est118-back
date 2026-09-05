<?php

namespace Database\Seeders;

use App\Models\Content\Event;
use App\Models\Content\Gallery;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class EventSeeder extends Seeder
{
    /**
     * Purge events and seed calendar + /eventos examples for visual debugging.
     */
    public function run(): void
    {
        if (! Schema::hasTable('events')) {
            $this->command?->warn('Tabla events no existe. Omite EventSeeder.');

            return;
        }

        Schema::disableForeignKeyConstraints();
        DB::table('events')->truncate();
        Schema::enableForeignKeyConstraints();

        $samples = [
            [
                'slug' => 'ceremonia-inicio-ciclo',
                'title' => 'Ceremonia de inicio de ciclo',
                'type' => 'Ceremonia',
                'summary' => 'Bienvenida oficial a estudiantes, familias y personal en el patio cívico.',
                'location' => 'Patio cívico',
                'important' => true,
                'gallery_slug' => 'ceremonia-civica',
                'cover' => $this->unsplash('photo-1523580846011-d3a5bc25702b', '16/9'),
                'starts_in_days' => 12,
                'duration_hours' => 2,
                'content_blocks' => [
                    ['type' => 'paragraph', 'text' => 'Honores a la bandera, palabras de dirección y presentación de los talleres que distinguen a la Técnica 118.'],
                    ['type' => 'list', 'items' => [
                        'Puntualidad: 7:50 h en el patio cívico.',
                        'Uniforme completo.',
                        'Familias de nuevo ingreso pueden acompañar el acto.',
                    ]],
                ],
            ],
            [
                'slug' => 'feria-ciencias-muestra-talleres',
                'title' => 'Feria de ciencias y muestra de talleres',
                'type' => 'Feria',
                'summary' => 'Proyectos de informática, diseño industrial, confección y máquinas abiertos a las familias.',
                'location' => 'Aula magna y talleres',
                'important' => true,
                'gallery_slug' => 'vida-en-los-talleres',
                'cover' => $this->unsplash('photo-1532094349884-543bc11b234d', '16/9'),
                'starts_in_days' => 20,
                'duration_hours' => 5,
                'content_blocks' => [
                    ['type' => 'paragraph', 'text' => 'Cada taller presentará stands con el trabajo del periodo. Las familias podrán recorrer el plantel y platicar con estudiantes y docentes.'],
                    ['type' => 'list', 'items' => [
                        'Informática y sistemas.',
                        'Diseño industrial.',
                        'Confección del vestido e industria textil.',
                        'Máquinas, herramientas y sistemas de control.',
                    ]],
                ],
            ],
            [
                'slug' => 'torneo-interescolar',
                'title' => 'Torneo deportivo interescolar',
                'type' => 'Torneo',
                'summary' => 'Fútbol, basquetbol y atletismo con escuelas de la zona.',
                'location' => 'Cancha principal',
                'important' => false,
                'gallery_slug' => 'deporte-en-la-cancha',
                'cover' => $this->unsplash('photo-1483721310020-03333e577078', '16/9'),
                'starts_in_days' => 28,
                'duration_hours' => 8,
                'content_blocks' => [
                    ['type' => 'paragraph', 'text' => 'El alumnado representará a la Técnica 118. Se invita a las familias a acompañar desde las gradas.'],
                    ['type' => 'paragraph', 'text' => 'Llevar gorra, agua y llegar con tiempo para el registro de equipos.'],
                ],
            ],
            [
                'slug' => 'junta-tutores-primer-grado',
                'title' => 'Junta de tutores — primer grado',
                'type' => 'Junta',
                'summary' => 'Lineamientos, talleres y canales de comunicación con la escuela.',
                'location' => 'Aula magna',
                'important' => true,
                'gallery_slug' => null,
                'cover' => $this->unsplash('photo-1503676260728-1c00da094a0b', '4/3'),
                'starts_in_days' => 6,
                'duration_hours' => 2,
                'content_blocks' => [
                    ['type' => 'paragraph', 'text' => 'Reunión informativa para madres, padres y tutores de primer grado.'],
                    ['type' => 'list', 'items' => [
                        'Llevar identificación.',
                        'Llegar 10 minutos antes.',
                        'Habrá un espacio para preguntas al final.',
                    ]],
                ],
            ],
            [
                'slug' => 'evaluaciones-periodo',
                'title' => 'Evaluaciones del periodo',
                'type' => 'Examen',
                'summary' => 'Semana de exámenes y entrega de proyectos por grado.',
                'location' => 'Aulas de cada grupo',
                'important' => false,
                'gallery_slug' => 'aula-y-comunidad-estudiantil',
                'cover' => $this->unsplash('photo-1434030216411-0b793f4b4173', '16/9'),
                'starts_in_days' => 35,
                'ends_in_days' => 39,
                'content_blocks' => [
                    ['type' => 'paragraph', 'text' => 'Las fechas por materia las confirma cada docente. Cualquier ajuste se publicará en avisos.'],
                ],
            ],
            [
                'slug' => 'visita-educativa-ciudad',
                'title' => 'Visita educativa a espacios culturales',
                'type' => 'Cultural',
                'summary' => 'Salida de segundo grado a museos y recintos de la ciudad de Oaxaca.',
                'location' => 'Centro histórico de Oaxaca',
                'important' => false,
                'gallery_slug' => 'excursiones-y-visitas',
                'cover' => $this->unsplash('photo-1476514525535-07fb3b4ae5f1', '16/9'),
                'starts_in_days' => 45,
                'duration_hours' => 6,
                'content_blocks' => [
                    ['type' => 'paragraph', 'text' => 'Permiso firmado y playera institucional. Punto de reunión: acceso principal del plantel a las 7:30 h.'],
                ],
            ],
            [
                'slug' => 'entrega-credenciales',
                'title' => 'Entrega de credenciales',
                'type' => 'Entrega',
                'summary' => 'Recoge la credencial en contraloría con identificación oficial.',
                'location' => 'Contraloría',
                'important' => false,
                'gallery_slug' => 'el-plantel-los-rios',
                'cover' => $this->unsplash('photo-1580582932707-520aed937b7b', '4/3'),
                'starts_in_days' => -4,
                'duration_hours' => 6,
                'content_blocks' => [
                    ['type' => 'paragraph', 'text' => 'Horario de contraloría: 7:15 a 9:30 y 10:00 a 13:30 h. Verifica los datos al recibirla.'],
                ],
            ],
            [
                'slug' => 'suspension-mantenimiento',
                'title' => 'Suspensión por mantenimiento eléctrico',
                'type' => 'Suspensión',
                'summary' => 'Sin clases presenciales el día de los trabajos en la instalación eléctrica.',
                'location' => 'Plantel Los Ríos',
                'important' => true,
                'gallery_slug' => null,
                'cover' => $this->unsplash('photo-1562774053-701939374585', '16/9'),
                'starts_in_days' => -18,
                'duration_hours' => 8,
                'content_blocks' => [
                    ['type' => 'paragraph', 'text' => 'Solo se atienden trámites urgentes en contraloría. Las clases se reanudan al día siguiente.'],
                ],
            ],
        ];

        foreach ($samples as $sample) {
            $starts = now()->addDays($sample['starts_in_days'])->setTime(8, 0);
            $ends = isset($sample['ends_in_days'])
                ? now()->addDays($sample['ends_in_days'])->setTime(14, 0)
                : (clone $starts)->addHours($sample['duration_hours'] ?? 2);

            $galleryId = null;
            if (! empty($sample['gallery_slug']) && Schema::hasTable('galleries')) {
                $galleryId = Gallery::query()->where('slug', $sample['gallery_slug'])->value('id');
            }

            $blocks = $sample['content_blocks'];
            if ($galleryId) {
                $blocks[] = [
                    'type' => 'gallery_ref',
                    'galleryId' => $galleryId,
                    'layout' => 'carousel',
                    'title' => 'Fotos del evento',
                ];
            }

            Event::create([
                'slug' => $sample['slug'],
                'title' => $sample['title'],
                'type' => $sample['type'],
                'summary' => $sample['summary'],
                'content_blocks' => $blocks,
                'starts_at' => $starts,
                'ends_at' => $ends,
                'location' => $sample['location'],
                'cover_src' => $sample['cover'],
                'important' => $sample['important'],
                'gallery_id' => $galleryId,
                'published_at' => now()->subDay(),
                'created_by' => null,
            ]);
        }

        $this->command?->info('Eventos limpiados y 8 actividades de ejemplo sembradas.');
    }

    private function unsplash(string $id, string $ratio): string
    {
        [$width, $height] = $ratio === '16/9' ? [1600, 900] : [1280, 960];

        return "https://images.unsplash.com/{$id}?auto=format&fit=crop&w={$width}&h={$height}&q=80";
    }
}
