<?php

namespace App\Console\Commands\temporal;

use App\Models\AcademicYear;
use Illuminate\Console\Command;

/**
 * Overwrite 2° primary tutors from DIRECTORIO 2° using the student's CURP.
 */
class MigrateSecondPrimaryFromDirectory extends Command
{
    protected $signature = 'admissions:migrate-second-primary-directory
                            {--file= : Ruta al Excel (default: storage/app/temp/listasExcel/DIRECTORIO 2° 26-27.xlsx)}
                            {--year= : ID del ciclo 2026-2027}
                            {--write : Aplica los cambios. Sin esta opción solo compara e imprime}';

    protected $description = 'Migra el tutor principal de 2° desde el directorio Excel (match por CURP del alumno). No toca tutores de 1° ni al secundario coincidente.';

    public function __construct(
        private readonly MigrateSecondPrimaryFromDirectoryService $service
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        @ini_set('memory_limit', '1024M');

        $yearId = $this->resolveYearId((int) $this->option('year'));
        if ($yearId <= 0) {
            $this->error('No se encontró el ciclo 2026-2027.');

            return self::FAILURE;
        }

        $path = (string) ($this->option('file') ?: SyncSecondGradePrimaryTutorsService::defaultPath());
        if (! is_file($path)) {
            $this->error('No se encontró el Excel: '.$path);

            return self::FAILURE;
        }

        $write = (bool) $this->option('write');
        $this->info($write
            ? 'Aplicando tutor principal de 2° desde el Excel…'
            : 'Comparación. Nada se escribe. Usa --write para aplicar.');

        $summary = $write
            ? $this->service->apply($path, $yearId)
            : $this->service->preview($path, $yearId);

        $this->newLine();
        $this->info('Ciclo '.$summary['academic_year_label'].' | hoja '.$summary['sheet']);
        $complete = count(array_filter($summary['plans'], fn (array $row) => ($row['completeness'] ?? '') === 'completo'));
        $partial = count($summary['plans']) - $complete;

        $this->table(
            ['Concepto', 'Cantidad'],
            [
                ['Filas en Excel', $summary['excel_rows']],
                ['Inscritos activos 2°', $summary['db_students']],
                [$write ? 'Aplicados' : 'Se aplicarían', $write ? $summary['applied'] : count($summary['plans'])],
                ['  → Excel completo', $complete],
                ['  → Excel parcial', $partial],
                ['Omitidos (es el secundario)', count($summary['skipped_secondary'])],
                ['Pendientes (tutor de 1°)', count($summary['pending_first'])],
                ['En Excel y no en 2°', count($summary['unmatched'])],
                ['En 2° y no en Excel', count($summary['only_in_db'])],
                ['Problemas', count($summary['problems'])],
            ]
        );

        $this->printPlans($summary['plans']);
        $this->printList('OMITIDOS — la CURP del Excel es el tutor secundario', $summary['skipped_secondary']);
        $this->printList('PENDIENTES — el tutor está ligado a 1° y no se toca', $summary['pending_first']);
        $this->printList('EN EXCEL Y NO EN 2° — la CURP del aspirante no está inscrita', $summary['unmatched']);
        $this->printList('EN 2° Y NO EN EXCEL — inscritos activos sin fila', $summary['only_in_db']);
        $this->printList('PROBLEMAS', $summary['problems']);

        return ($summary['problems'] ?? []) === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<array<string, mixed>>  $plans
     */
    private function printPlans(array $plans): void
    {
        $this->newLine();
        $this->warn('=== CAMBIOS DE TUTOR PRINCIPAL — '.count($plans).' ===');
        if ($plans === []) {
            $this->line('  Ninguno.');

            return;
        }

        $this->table(
            ['Fila', 'Alumno', 'Datos', 'Faltó', 'Modo', 'Antes', 'Excel', 'También ligado a'],
            array_map(function (array $row) {
                $missing = $row['missing'] ?? [];

                return [
                    $row['excel_row'],
                    $row['student'],
                    ($row['completeness'] ?? 'parcial') === 'completo' ? 'completo' : 'parcial',
                    $missing === [] ? '—' : implode(', ', $missing),
                    $row['mode'],
                    $row['before'],
                    $row['after'],
                    $row['also_linked'] !== '' ? $row['also_linked'] : '—',
                ];
            }, $plans)
        );
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function printList(string $title, array $rows): void
    {
        $this->newLine();
        $this->warn('=== '.$title.' — '.count($rows).' ===');
        if ($rows === []) {
            $this->line('  Ninguno.');

            return;
        }

        foreach ($rows as $index => $row) {
            $this->line(sprintf(
                '  %d. fila %s | %s | CURP %s | %s',
                $index + 1,
                $row['excel_row'] ?? '—',
                $row['student'] ?? '—',
                ($row['curp'] ?? '') !== '' ? $row['curp'] : '—',
                $row['reason'] ?? ''
            ));
        }
    }

    private function resolveYearId(int $yearId): int
    {
        $query = AcademicYear::query()->where('year_start', '2026')->where('year_end', '2027');
        if ($yearId > 0) {
            $query->whereKey($yearId);
        }

        return (int) ($query->value('id') ?? 0);
    }
}
