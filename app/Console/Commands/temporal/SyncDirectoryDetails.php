<?php

namespace App\Console\Commands\temporal;

use App\Models\AcademicYear;
use Illuminate\Console\Command;
use Throwable;

class SyncDirectoryDetails extends Command
{
    protected $signature = 'admissions:sync-directory-details
                            {--grade=todos : Grado de la lista: 1, 2, 3 o todos}
                            {--file= : Ruta al Excel (solo con un grado)}
                            {--year= : ID del ciclo 2026-2027}
                            {--write : Escribe CURP, teléfonos, domicilios y tutores nuevos}';

    protected $description = 'Actualiza fichas de 2026-2027 desde los directorios: el Excel gana en CURP no exacta, teléfono del tutor y domicilio; si el tutor es otro, lo crea y lo enlaza. Sin --write solo simula.';

    private const FILE_HINTS = [
        '1°' => 'PRIMERO',
        '2°' => 'SEGUNDO',
        '3°' => 'TERCER',
    ];

    public function __construct(
        private readonly SyncDirectoryDetailsService $details
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

        $grades = $this->grades((string) $this->option('grade'));
        if ($grades === []) {
            $this->error('Grado inválido. Usa 1, 2, 3 o todos.');

            return self::FAILURE;
        }
        if ($this->option('file') && count($grades) > 1) {
            $this->error('--file solo se puede usar con un grado.');

            return self::FAILURE;
        }

        $dryRun = ! $this->option('write');
        $this->info($dryRun
            ? 'Simulación. Nada se escribió. Usa --write para aplicar.'
            : 'Aplicando cambios en fichas de 2026-2027.');
        $this->comment('Solo cambia la CURP cuando no coincide exacta y el alumno se reconoce por CURP parecida o por nombre.');
        $this->comment('El teléfono de contacto es del tutor y siempre gana el del Excel. El domicilio también.');

        $failed = false;
        foreach ($grades as $grade) {
            $path = (string) ($this->option('file') ?: $this->defaultFile($grade));
            $this->newLine();
            $this->line(str_repeat('=', 72));
            if ($path === '') {
                $this->warn('Grado '.$grade.': no se encontró su directorio en storage/app/temp/listasExcel.');

                continue;
            }

            try {
                $summary = $this->details->sync($path, $yearId, $grade, $dryRun);
            } catch (Throwable $exception) {
                $this->error('Grado '.$grade.': '.$exception->getMessage());
                $failed = true;

                continue;
            }

            $this->printSummary($summary, $path, $dryRun);
            $failed = $failed || $summary['errors'] !== [];
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function printSummary(array $summary, string $path, bool $dryRun): void
    {
        $this->info('Grado '.$summary['grade'].' | ciclo '.$summary['academic_year_label']);
        $this->line('Archivo: '.basename($path).' | hoja '.$summary['sheet']);
        $this->line('Filas del directorio: '.$summary['rows']);
        $this->line('Emparejados con inscritos de 2026-2027: '.$summary['matched']);
        $this->line($dryRun
            ? 'Alumnos que se actualizarían: '.$this->touchedCount($summary)
            : 'Alumnos actualizados: '.$summary['applied']);
        $this->line('CURP tomadas del Excel: '.count($summary['curp_changes']));
        $this->line('Teléfonos de tutor cambiados: '.count($summary['phone_changes']));
        $this->line('Correos cambiados: '.count($summary['email_changes']));
        $this->line('Domicilios cambiados: '.count($summary['address_changes']));
        $this->line('Tutores agregados: '.count($summary['tutors_added']));
        $this->line('Filas sin coincidencia u observaciones: '.count($summary['skipped']));
        $this->line('Inscritos de 2026-2027 que no aparecen en la lista: '.count($summary['not_on_list']));
        $this->line('Errores: '.count($summary['errors']));

        $this->printCurps($summary['curp_changes']);
        $this->printContacts('Teléfonos de contacto (tutor) tomados del Excel', $summary['phone_changes']);
        $this->printContacts('Correos tomados del Excel', $summary['email_changes']);
        $this->printAddresses($summary['address_changes']);
        $this->printTutors($summary['tutors_added']);
        $this->printIssues('Filas sin coincidencia u observaciones', $summary['skipped']);
        $this->printNotOnList($summary['not_on_list']);
        $this->printIssues('Errores', $summary['errors']);
    }

    private function resolveYearId(int $yearId): int
    {
        $query = AcademicYear::query()->where('year_start', '2026')->where('year_end', '2027');
        if ($yearId > 0) {
            $query->whereKey($yearId);
        }

        return (int) ($query->value('id') ?? 0);
    }

    /**
     * @return list<string>
     */
    private function grades(string $raw): array
    {
        return match (mb_strtolower(trim($raw))) {
            '1', '1°', 'primero', 'primeros' => ['1°'],
            '2', '2°', 'segundo', 'segundos' => ['2°'],
            '3', '3°', 'tercero', 'terceros' => ['3°'],
            '', 'todos', 'all' => ['1°', '2°', '3°'],
            default => [],
        };
    }

    private function defaultFile(string $grade): string
    {
        $directory = storage_path('app/temp/listasExcel');
        foreach (glob($directory.DIRECTORY_SEPARATOR.'*.xlsx') ?: [] as $file) {
            if (str_contains(mb_strtoupper(basename($file)), self::FILE_HINTS[$grade])) {
                return $file;
            }
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function touchedCount(array $summary): int
    {
        $names = [];
        foreach (['curp_changes', 'phone_changes', 'email_changes', 'tutors_added', 'address_changes'] as $key) {
            foreach ($summary[$key] as $row) {
                $names[$row['student']] = true;
            }
        }

        return count($names);
    }

    /**
     * @param  list<array{student: string, from: string, to: string, how: string}>  $rows
     */
    private function printCurps(array $rows): void
    {
        $this->newLine();
        $this->warn('CURP tomadas del Excel ('.count($rows).'):');
        if ($rows === []) {
            $this->line('  Ninguna.');

            return;
        }
        foreach ($rows as $index => $row) {
            $this->line(sprintf('  %d. %s | %s → %s | %s', $index + 1, $row['student'], $row['from'], $row['to'], $row['how']));
        }
    }

    /**
     * @param  list<array{student: string, who: string, from: string, to: string}>  $rows
     */
    private function printContacts(string $title, array $rows): void
    {
        $this->newLine();
        $this->warn($title.' ('.count($rows).'):');
        if ($rows === []) {
            $this->line('  Ninguno.');

            return;
        }
        foreach ($rows as $index => $row) {
            $this->line(sprintf('  %d. %s | %s | %s → %s', $index + 1, $row['student'], $row['who'], $row['from'], $row['to']));
        }
    }

    /**
     * @param  list<array{student: string, from: string, to: string}>  $rows
     */
    private function printAddresses(array $rows): void
    {
        $this->newLine();
        $this->warn('Domicilios tomados del Excel ('.count($rows).'):');
        if ($rows === []) {
            $this->line('  Ninguno.');

            return;
        }
        foreach ($rows as $index => $row) {
            $this->line(sprintf('  %d. %s', $index + 1, $row['student']));
            $this->line('     antes: '.$row['from']);
            $this->line('     ahora: '.$row['to']);
        }
    }

    /**
     * @param  list<array{student: string, tutor: string, curp: string, kinship: string, phone: string, email: string}>  $rows
     */
    private function printTutors(array $rows): void
    {
        $this->newLine();
        $this->warn('Tutores agregados y enlazados ('.count($rows).'):');
        if ($rows === []) {
            $this->line('  Ninguno.');

            return;
        }
        foreach ($rows as $index => $row) {
            $this->line(sprintf(
                '  %d. Alumno %s | tutor %s | CURP %s | %s%s%s',
                $index + 1,
                $row['student'],
                $row['tutor'],
                $row['curp'],
                $row['kinship'],
                $row['phone'] !== '' ? ' | tel '.$row['phone'] : '',
                $row['email'] !== '' ? ' | '.$row['email'] : ''
            ));
        }
    }

    /**
     * @param  list<array{curp: string, name: string, group: string}>  $rows
     */
    private function printNotOnList(array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $this->newLine();
        $this->warn('Inscritos de 2026-2027 que no aparecen en la lista ('.count($rows).'):');
        foreach ($rows as $index => $row) {
            $this->line(sprintf('  %d. %s | CURP %s | grupo %s', $index + 1, $row['name'], $row['curp'], $row['group'] !== '' ? $row['group'] : '—'));
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
