<?php

namespace Database\Seeders;

use App\Models\Content\Gallery;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class GallerySeeder extends Seeder
{
    /**
     * Purge albums and seed example galleries for visual debugging.
     * Photos are Unsplash stills of students, workshops, sports and school places.
     */
    public function run(): void
    {
        if (! Schema::hasTable('galleries') || ! Schema::hasTable('gallery_items')) {
            $this->command?->warn('Tablas de galería no existen. Omite GallerySeeder.');

            return;
        }

        Schema::disableForeignKeyConstraints();
        if (Schema::hasTable('events') && Schema::hasColumn('events', 'gallery_id')) {
            DB::table('events')->update(['gallery_id' => null]);
        }
        DB::table('gallery_items')->truncate();
        DB::table('galleries')->truncate();
        Schema::enableForeignKeyConstraints();

        $albums = [
            [
                'slug' => 'vida-en-los-talleres',
                'title' => 'Vida en los talleres',
                'description' => 'Informática, diseño industrial, confección y máquinas: el día a día de la Técnica 118.',
                'category' => 'Talleres',
                'featured' => true,
                'days_ago' => 3,
                'items' => [
                    $this->photo('photo-1581091226825-a6a2a5aee158', 'Estudiantes trabajando con computadoras en el taller de informática', '4/3', 'Práctica de informática'),
                    $this->photo('photo-1516321318423-f06f85e504b3', 'Joven usando una laptop en clase', '16/9', 'Sesión de programación'),
                    $this->photo('photo-1581092160562-40aa08e78837', 'Persona con equipo de ingeniería en un taller', '4/3', 'Máquinas y control'),
                    $this->photo('photo-1504148455328-c376907d081c', 'Herramientas sobre un banco de trabajo', '4/3', 'Banco de herramientas'),
                    $this->photo('photo-1558618666-fcd25c85cd64', 'Tela y máquina de coser en un taller de confección', '3/4', 'Confección del vestido'),
                    $this->photo('photo-1452860606245-08befc0ff44b', 'Mesa de diseño con materiales y bocetos', '4/3', 'Diseño industrial'),
                    $this->photo('photo-1581094794329-c8112a89af12', 'Persona con casco en un espacio de manufactura', '16/9', 'Práctica industrial'),
                    $this->photo('photo-1561070791-2526d30994b5', 'Materiales de diseño sobre una mesa', '1/1', 'Proceso creativo'),
                ],
            ],
            [
                'slug' => 'aula-y-comunidad-estudiantil',
                'title' => 'Aula y comunidad estudiantil',
                'description' => 'Clases, lectura y el trabajo diario de estudiantes y docentes.',
                'category' => 'Académico',
                'featured' => false,
                'days_ago' => 10,
                'items' => [
                    $this->photo('photo-1509062522246-3755977927d7', 'Grupo de estudiantes en un aula', '16/9', 'Trabajo en el aula'),
                    $this->photo('photo-1427504494785-3a9ca7044f45', 'Estudiantes frente a computadoras', '4/3', 'Sala de cómputo'),
                    $this->photo('photo-1434030216411-0b793f4b4173', 'Estudiante escribiendo en un cuaderno', '4/3', 'Acompañamiento docente'),
                    $this->photo('photo-1503676260728-1c00da094a0b', 'Estudiantes leyendo en clase', '3/4', 'Lectura en el aula'),
                    $this->photo('photo-1524178232363-1fb2b075b655', 'Estudiantes sentados en un auditorio', '16/9', 'Sesión en aula magna'),
                    $this->photo('photo-1521587760476-6c12a4b040da', 'Estantería de una biblioteca escolar', '3/4', 'Biblioteca'),
                ],
            ],
            [
                'slug' => 'deporte-en-la-cancha',
                'title' => 'Deporte en la cancha',
                'description' => 'Entrenamientos y torneos de la comunidad de la Técnica 118.',
                'category' => 'Deportes',
                'featured' => false,
                'days_ago' => 18,
                'items' => [
                    $this->photo('photo-1483721310020-03333e577078', 'Atletas corriendo en una pista', '16/9', 'Atletismo'),
                    $this->photo('photo-1546519638-68e109498ffc', 'Jugadores de basquetbol en la cancha', '4/3', 'Basquetbol'),
                    $this->photo('photo-1574629810360-7efbbe195018', 'Jugadores de fútbol en el campo', '16/9', 'Fútbol'),
                    $this->photo('photo-1552674605-db6ffd4facb5', 'Persona en competencia atlética', '3/4', 'Competencia'),
                    $this->photo('photo-1551958219-acbc608c6377', 'Cancha de fútbol al atardecer', '16/9', 'Cancha principal'),
                    $this->photo('photo-1579952363873-27f3bade9f55', 'Balón de fútbol en el césped', '1/1', 'Entrenamiento'),
                ],
            ],
            [
                'slug' => 'ceremonia-civica',
                'title' => 'Ceremonia cívica y reconocimientos',
                'description' => 'Actos cívicos, honores a la bandera y reconocimientos a estudiantes.',
                'category' => 'Ceremonias',
                'featured' => false,
                'days_ago' => 25,
                'items' => [
                    $this->photo('photo-1523580846011-d3a5bc25702b', 'Estudiantes en una ceremonia de graduación', '16/9', 'Ceremonia de generación'),
                    $this->photo('photo-1627556704290-2b1f5853ff78', 'Jóvenes con toga en un acto académico', '4/3', 'Reconocimientos'),
                    $this->photo('photo-1523580494863-6f3031224c94', 'Público sentado en un acto escolar', '16/9', 'Comunidad reunida'),
                    $this->photo('photo-1541339907198-e08756dedf3f', 'Fachada de un edificio escolar', '4/3', 'Plantel'),
                    $this->photo('photo-1524178232363-1fb2b075b655', 'Estudiantes en el aula magna', '16/9', 'Acto en aula magna'),
                ],
            ],
            [
                'slug' => 'excursiones-y-visitas',
                'title' => 'Excursiones y visitas',
                'description' => 'Salidas educativas, paisaje y visitas de la comunidad estudiantil.',
                'category' => 'Excursiones',
                'featured' => false,
                'days_ago' => 40,
                'items' => [
                    $this->photo('photo-1469854523086-cc02fe5d8800', 'Camino y paisaje durante un viaje', '16/9', 'En el camino'),
                    $this->photo('photo-1476514525535-07fb3b4ae5f1', 'Lago y montañas en una visita educativa', '16/9', 'Paisaje'),
                    $this->photo('photo-1506905925346-21bda4d32df4', 'Montañas al atardecer', '4/3', 'Mirador'),
                    $this->photo('photo-1501785888041-af3ef285b470', 'Sendero entre montañas', '3/4', 'Caminata'),
                    $this->photo('photo-1470071459604-3b5ec3a7fe05', 'Bosque y cielo abierto', '16/9', 'Área natural'),
                    $this->photo('photo-1441974231531-c6227db76b6e', 'Bosque iluminado por el sol', '4/3', 'Visita al bosque'),
                ],
            ],
            [
                'slug' => 'el-plantel-los-rios',
                'title' => 'El plantel en Los Ríos',
                'description' => 'Espacios, pasillos y el entorno de la Escuela Secundaria Técnica No. 118.',
                'category' => 'Comunidad',
                'featured' => false,
                'days_ago' => 12,
                'items' => [
                    $this->photo('photo-1562774053-701939374585', 'Edificio escolar visto desde el patio', '16/9', 'Fachada del plantel'),
                    $this->photo('photo-1580582932707-520aed937b7b', 'Aula vacía con pizarrón', '4/3', 'Salón de clases'),
                    $this->photo('photo-1497633762265-9d179a990aa6', 'Libros apilados sobre una mesa', '3/4', 'Material escolar'),
                    $this->photo('photo-1541339907198-e08756dedf3f', 'Campus y jardines de una escuela', '4/3', 'Patio y jardines'),
                    $this->photo('photo-1524178232363-1fb2b075b655', 'Estudiantes caminando en un pasillo amplio', '16/9', 'Pasillo principal'),
                    $this->photo('photo-1509062522246-3755977927d7', 'Estudiantes conversando en el aula', '4/3', 'Recreo en el aula'),
                ],
            ],
        ];

        foreach ($albums as $album) {
            $items = $album['items'];
            unset($album['items']);

            $gallery = Gallery::create([
                'slug' => $album['slug'],
                'title' => $album['title'],
                'description' => $album['description'],
                'category' => $album['category'],
                'cover_src' => $items[0]['media_src'],
                'featured' => $album['featured'],
                'published_at' => now()->subDays($album['days_ago']),
                'created_by' => null,
            ]);

            $gallery->items()->createMany(
                collect($items)->values()->map(fn (array $item, int $index): array => [
                    ...$item,
                    'sort_order' => $index,
                ])->all()
            );
        }

        $this->command?->info('Galerías limpiadas y 6 álbumes de ejemplo sembrados.');
    }

    /**
     * @return array{media_src: string, alt: string, caption: string, ratio: string}
     */
    private function photo(string $id, string $alt, string $ratio, string $caption): array
    {
        return [
            'media_src' => $this->unsplash($id, $ratio),
            'alt' => $alt,
            'caption' => $caption,
            'ratio' => $ratio,
        ];
    }

    private function unsplash(string $id, string $ratio): string
    {
        [$width, $height] = match ($ratio) {
            '3/4' => [960, 1280],
            '1/1' => [1080, 1080],
            '16/9' => [1600, 900],
            default => [1280, 960],
        };

        return "https://images.unsplash.com/{$id}?auto=format&fit=crop&w={$width}&h={$height}&q=80";
    }
}
