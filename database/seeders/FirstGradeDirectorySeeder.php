<?php

namespace Database\Seeders;

use App\Console\Commands\temporal\ApplyFirstGradeRosterService;
use Illuminate\Database\Seeder;
use RuntimeException;

class FirstGradeDirectorySeeder extends Seeder
{
    public function run(): void
    {
        //**correr seeder: php artisan db:seed --class=FirstGradeDirectorySeeder */
        $path = ApplyFirstGradeRosterService::resolveDefaultFile();
        if ($path === '') {
            throw new RuntimeException('No se encontró DIRECTORIO PRIMEROS. Colócalo en storage/app/temp/listasExcel o listasExcel/.');
        }

        $yearId = ApplyFirstGradeRosterService::resolveYearId();
        if ($yearId <= 0) {
            throw new RuntimeException('No hay ciclo destino. Abre un periodo de reinscripción o indica un ciclo activo.');
        }

        $summary = app(ApplyFirstGradeRosterService::class)->apply($path, $yearId, dryRun: false);

        $this->command?->info('Directorio de 1° aplicado en ciclo '.$summary['academic_year_label'].'.');
        $this->command?->line('Filas: '.$summary['rows']);
        $this->command?->line('En preinscripción: '.$summary['in_pre']);
        $this->command?->line('Sin preinscripción: '.$summary['not_in_pre_count']);
        $this->command?->line('Convertidos: '.count($summary['converted']));
        $this->command?->line('Altas tardías: '.count($summary['created_late']));
        $this->command?->line('Ya alumnos inscritos en destino: '.count($summary['placed_existing']));
        $this->command?->line('Ya en 1° destino: '.count($summary['already_in_first']));
        $this->command?->line('1° destino fuera de listas: '.count($summary['dest_first_not_on_list']));
        $this->command?->line('1° origen fuera de listas: '.count($summary['origin_first_not_on_list']));
        $this->command?->line('Procesados: '.$summary['applied']);
        $this->command?->line('Errores: '.count($summary['errors']));
    }
}
