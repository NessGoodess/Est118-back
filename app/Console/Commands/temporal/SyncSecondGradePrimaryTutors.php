<?php

namespace App\Console\Commands\temporal;

use App\Models\AcademicYear;
use Illuminate\Console\Command;

/**
 * Temporal: list 2° Excel↔DB matches for primary tutor updates, then confirm before writing.
 */
class SyncSecondGradePrimaryTutors extends Command
{
    protected $signature = 'admissions:sync-second-primary-tutors
                            {--file= : Ruta al Excel (default: storage/app/temp/listasExcel/DIRECTORIO 2° 26-27.xlsx)}
                            {--year= : ID del ciclo 2026-2027}
                            {--dry-run : Solo lista coincidencias / no coincidencias; no escribe}
                            {--yes : Aplica sin preguntar (después de listar)}';

    protected $description = '[temporal] Actualiza CURP/teléfono/parentesco del tutor principal de 2° desde DIRECTORIO 2° 26-27.xlsx. Lista coincidentes y no coincidentes; pide confirmación antes de escribir.';

    public function __construct(
        private readonly SyncSecondGradePrimaryTutorsService $service
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

        $this->info('Leyendo Excel y emparejando con inscritos activos de 2°…');
        $summary = $this->service->preview($path, $yearId);

        $this->printOverview($summary, $path);
        $this->printMatched($summary['matched']);
        $this->printUnmatchedExcel($summary['unmatched_excel']);
        $this->printUnmatchedDb($summary['unmatched_db']);

        $toUpdate = array_values(array_filter(
            $summary['matched'],
            fn (array $row) => $row['will_update']
        ));

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment('Dry-run: no se escribió nada. Quita --dry-run para pedir confirmación y aplicar.');

            return self::SUCCESS;
        }

        if ($toUpdate === []) {
            $this->newLine();
            $this->info('No hay alumnos coincidentes con cambios pendientes. Los no coincidentes quedan para revisión manual.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('Se actualizaría el tutor principal de '.count($toUpdate).' alumno(s) coincidente(s).');
        $this->line('Los no coincidentes no se tocan; puedes corregirlos a mano después.');

        if (! $this->option('yes') && ! $this->confirm('¿Continuar con la actualización de los coincidentes?', false)) {
            $this->comment('Cancelado. No se escribió nada.');

            return self::SUCCESS;
        }

        $result = $this->service->apply($path, $yearId);

        $this->newLine();
        $this->info('Actualizados: '.$result['applied']);
        if ($result['errors'] !== []) {
            $this->error('Errores al aplicar ('.count($result['errors']).'):');
            foreach ($result['errors'] as $index => $error) {
                $this->line(sprintf('  %d. %s — %s', $index + 1, $error['student'], $error['reason']));
            }

            return self::FAILURE;
        }

        $this->info('Listo. Revisa la lista de no coincidentes para completar a mano.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function printOverview(array $summary, string $path): void
    {
        $withChanges = count(array_filter($summary['matched'], fn (array $row) => $row['will_update']));
        $matchedOk = count($summary['matched']) - $withChanges;

        $this->newLine();
        $this->info('Ciclo '.$summary['academic_year_label'].' | hoja '.$summary['sheet']);
        $this->line('Archivo: '.basename($path));
        $this->table(
            ['Concepto', 'Cantidad'],
            [
                ['Filas en Excel', $summary['excel_rows']],
                ['Inscritos activos 2° (BD)', $summary['db_students']],
                ['Coinciden Excel↔BD', count($summary['matched'])],
                ['  → con cambios de tutor', $withChanges],
                ['  → ya iguales / sin dato útil', $matchedOk],
                ['No coinciden (Excel)', count($summary['unmatched_excel'])],
                ['Solo en BD (no en Excel)', count($summary['unmatched_db'])],
            ]
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function printMatched(array $rows): void
    {
        $this->newLine();
        $this->warn('=== COINCIDEN (Excel ↔ BD) — '.count($rows).' ===');
        if ($rows === []) {
            $this->line('  Ninguno.');

            return;
        }

        $this->table(
            ['#', 'Grupo', 'Alumno', 'Match', 'Tutor BD', 'Cambios / notas'],
            array_map(function (array $row, int $index) {
                $notes = [];
                if ($row['changes'] !== []) {
                    $notes[] = implode('; ', $row['changes']);
                }
                if ($row['problems'] !== []) {
                    $notes[] = '⚠ '.implode('; ', $row['problems']);
                }
                if ($notes === []) {
                    $notes[] = 'sin cambios';
                }

                return [
                    $index + 1,
                    $row['group'] !== '' ? $row['group'] : '—',
                    $row['student'],
                    $row['method'],
                    $row['db_tutor'],
                    implode(' | ', $notes),
                ];
            }, $rows, array_keys($rows))
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function printUnmatchedExcel(array $rows): void
    {
        $this->newLine();
        $this->warn('=== NO COINCIDEN (solo Excel / ambiguos) — '.count($rows).' ===');
        if ($rows === []) {
            $this->line('  Ninguno.');

            return;
        }

        $this->table(
            ['Fila', 'Grupo', 'Alumno Excel', 'CURP', 'Tutor Excel', 'Motivo'],
            array_map(fn (array $row) => [
                $row['excel_row'],
                $row['group'] !== '' ? $row['group'] : '—',
                $row['student'] !== '' ? $row['student'] : '—',
                $row['curp'] !== '' ? $row['curp'] : '—',
                $row['excel_tutor'] !== '' ? $row['excel_tutor'] : '—',
                $row['reason'],
            ], $rows)
        );
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function printUnmatchedDb(array $rows): void
    {
        $this->newLine();
        $this->warn('=== SOLO EN BD (inscritos 2° sin pareja en Excel) — '.count($rows).' ===');
        if ($rows === []) {
            $this->line('  Ninguno.');

            return;
        }

        $this->table(
            ['ID', 'Grupo', 'Alumno', 'CURP', 'Motivo'],
            array_map(fn (array $row) => [
                $row['student_id'],
                $row['group'] !== '' ? $row['group'] : '—',
                $row['student'],
                $row['curp'] !== '' ? $row['curp'] : '—',
                $row['reason'],
            ], $rows)
        );
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
