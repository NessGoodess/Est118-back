<?php

namespace App\Console\Commands\temporal;

use App\Models\AcademicYear;
use Illuminate\Console\Command;
use Throwable;

class SyncDirectoryWorkshops extends Command
{
    protected $signature = 'admissions:sync-directory-workshops
                            {--grade=todos : Grado de la lista: 1, 2, 3 o todos}
                            {--write : Cambia o asigna el taller}';

    protected $description = 'Solo talleres: a los inscritos activos de 2026-2027 les pone el taller del directorio y asigna el que falte. Sin --write solo simula.';

    private const FILE_HINTS = [
        '1°' => 'PRIMERO',
        '2°' => 'SEGUNDO',
        '3°' => 'TERCER',
    ];

    public function __construct(
        private readonly SyncDirectoryWorkshopsService $service
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        @ini_set('memory_limit', '1024M');

        $yearId = (int) (AcademicYear::query()->where('year_start', '2026')->where('year_end', '2027')->value('id') ?? 0);
        if ($yearId <= 0) {
            $this->error('No se encontró el ciclo 2026-2027.');

            return self::FAILURE;
        }

        $grades = match (mb_strtolower(trim((string) $this->option('grade')))) {
            '1', '1°', 'primero', 'primeros' => ['1°'],
            '2', '2°', 'segundo', 'segundos' => ['2°'],
            '3', '3°', 'tercero', 'terceros' => ['3°'],
            '', 'todos', 'all' => ['1°', '2°', '3°'],
            default => [],
        };
        if ($grades === []) {
            $this->error('Grado inválido. Usa 1, 2, 3 o todos.');

            return self::FAILURE;
        }

        $dryRun = ! $this->option('write');
        $this->info($dryRun
            ? 'Simulación. Nada se escribió. Usa --write para aplicar.'
            : 'Aplicando talleres del directorio en 2026-2027.');

        $failed = false;
        $changed = [];
        $added = [];
        foreach ($grades as $grade) {
            $path = $this->defaultFile($grade);
            $this->newLine();
            $this->line(str_repeat('=', 72));
            if ($path === '') {
                $this->warn('Grado '.$grade.': no se encontró su directorio en storage/app/temp/listasExcel.');

                continue;
            }

            try {
                $summary = $this->service->sync($path, $yearId, $grade, $dryRun);
            } catch (Throwable $exception) {
                $this->error('Grado '.$grade.': '.$exception->getMessage());
                $failed = true;

                continue;
            }

            $this->info('Grado '.$summary['grade'].' | ciclo '.$summary['academic_year_label'].' | '.basename($path).' | hoja '.$summary['sheet']);
            $this->line('Filas del directorio: '.$summary['rows']);
            $this->line('Emparejados con inscritos activos: '.$summary['matched']);
            $this->line('Ya tenían el taller correcto: '.$summary['unchanged']);
            $this->line('Talleres cambiados: '.count($summary['changed']));
            $this->line('Talleres agregados (no tenían): '.count($summary['added']));
            if (! $dryRun) {
                $this->line('Aplicados: '.$summary['applied']);
            }
            $this->line('Filas sin cambio de taller: '.count($summary['skipped']));
            $this->line('Errores: '.count($summary['errors']));
            $this->printIssues('Filas sin cambio de taller', $summary['skipped']);
            $this->printIssues('Errores', $summary['errors']);

            array_push($changed, ...$summary['changed']);
            array_push($added, ...$summary['added']);
            $failed = $failed || $summary['errors'] !== [];
        }

        $this->newLine();
        $this->line(str_repeat('=', 72));
        $this->printWorkshops('Talleres cambiados', $changed);
        $this->printWorkshops('Talleres agregados (no tenía taller)', $added);

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function defaultFile(string $grade): string
    {
        foreach (glob(storage_path('app/temp/listasExcel').DIRECTORY_SEPARATOR.'*.xlsx') ?: [] as $file) {
            if (str_contains(mb_strtoupper(basename($file)), self::FILE_HINTS[$grade])) {
                return $file;
            }
        }

        return '';
    }

    /**
     * @param  list<array{student: string, curp: string, group: string, from: string, to: string}>  $rows
     */
    private function printWorkshops(string $title, array $rows): void
    {
        $this->newLine();
        $this->warn($title.' ('.count($rows).'):');
        if ($rows === []) {
            $this->line('  Ninguno.');

            return;
        }
        foreach ($rows as $index => $row) {
            $this->line(sprintf(
                '  %d. %s | %s | %s | %s → %s',
                $index + 1,
                $row['student'],
                $row['curp'],
                $row['group'],
                $row['from'],
                $row['to']
            ));
        }
    }

    /**
     * @param  list<array{row: string, curp: string, name: string, reason: string}>  $rows
     */
    private function printIssues(string $title, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->newLine();
        $this->warn($title.' ('.count($rows).'):');
        foreach ($rows as $row) {
            $this->line('  fila '.$row['row'].' '.($row['curp'] !== '' ? $row['curp'] : 'sin CURP').' '.$row['name'].' — '.$row['reason']);
        }
    }
}
