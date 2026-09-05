<?php

namespace Database\Seeders;

use App\Models\Content\IdentityBanner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class IdentityBannerSeeder extends Seeder
{
    /**
     * Purge identity banners and seed the home carousel from the original frontend mock.
     */
    public function run(): void
    {
        if (! Schema::hasTable('identity_banners')) {
            $this->command?->warn('Tabla identity_banners no existe. Omite IdentityBannerSeeder.');

            return;
        }

        Schema::disableForeignKeyConstraints();
        DB::table('identity_banners')->truncate();
        Schema::enableForeignKeyConstraints();

        $samples = [
            [
                'slug' => 'comunidad',
                'src' => $this->wideUnsplash('photo-1524178232363-1fb2b075b655'),
                'alt' => 'Estudiantes reunidos en un aula magna',
                'eyebrow' => 'Comunidad estudiantil',
                'title' => 'Así se vive la Técnica 118',
                'description' => 'Aulas, talleres y el día a día de quienes estudian en Los Ríos.',
                'href' => '/galeria',
                'cta' => 'Ver galería',
                'sort_order' => 1,
            ],
            [
                'slug' => 'talleres',
                'src' => $this->wideUnsplash('photo-1581091226825-a6a2a5aee158'),
                'alt' => 'Estudiantes trabajando con computadoras en un taller',
                'eyebrow' => 'Formación técnica',
                'title' => 'Cuatro talleres, una identidad',
                'description' => 'Informática, diseño industrial, confección y máquinas.',
                'href' => '/#talleres',
                'cta' => 'Conocer talleres',
                'sort_order' => 2,
            ],
            [
                'slug' => 'aula',
                'src' => $this->wideUnsplash('photo-1509062522246-3755977927d7'),
                'alt' => 'Grupo de estudiantes en el aula',
                'eyebrow' => 'Vida académica',
                'title' => 'Aprender juntos en el aula',
                'description' => 'Secundaria pública con el sello de la formación técnica.',
                'href' => '/#vida',
                'cta' => 'Ver vida escolar',
                'sort_order' => 3,
            ],
            [
                'slug' => 'plantel',
                'src' => $this->wideUnsplash('photo-1562774053-701939374585'),
                'alt' => 'Edificio escolar visto desde el patio',
                'eyebrow' => 'El plantel',
                'title' => 'En Fraccionamiento los Ríos',
                'description' => 'Oaxaca de Juárez · CCT 09DST0118V · IEEPO',
                'href' => '/#ubicacion',
                'cta' => 'Cómo llegar',
                'sort_order' => 4,
            ],
        ];

        foreach ($samples as $sample) {
            IdentityBanner::create([
                ...$sample,
                'show_copy' => true,
                'show_cta' => true,
                'published_at' => now()->subDay(),
                'created_by' => null,
            ]);
        }

        $this->command?->info('Banners de identidad limpiados y 4 slides de ejemplo sembrados.');
    }

    private function wideUnsplash(string $id): string
    {
        return "https://images.unsplash.com/{$id}?auto=format&fit=crop&w=2400&h=900&q=80";
    }
}
