<?php

namespace App\Console\Commands\temporal;

use App\Models\AcademicYear;
use Illuminate\Console\Command;
use Throwable;

class SyncDirectoryDetails extends Command
{
    protected $signature = 'admissions:sync-directory-details
                            {--file= : Ruta al Excel de directorio}
                            {--year= : ID del ciclo 2026-2027}
                            {--grade=1 : Grado de la lista (1, 2 o 3)}
                            {--write : Escribe teléfonos, correos y tutores nuevos}';

    protected $description = 'Actualiza fichas de 2026-2027 desde el directorio: el Excel gana en teléfonos y correos; si el tutor es otro, lo crea y lo enlaza. Sin --write solo simula.';

    public function __construct(
        private readonly SyncDirectoryDetailsService $details
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        @ini_set('memory_limit', '512M');

        $yearId = $this->resolveYearId((int) $this->option('year'));
        if ($yearId <= 0) {
            $this->error('No se encontró el ciclo 2026-2027.');

            return self::FAILURE;
        }

        $grade = $this->gradeName((string) $this->option('grade'));
        $path = (string) ($this->option('file') ?: ApplyFirstGradeRosterService::resolveDefaultFile());
        if ($path === '') {
            $this->error('No se encontró el Excel. Pásalo con --file.');

            return self::FAILURE;
        }

        $dryRun = ! $this->option('write');

        try {
            $summary = $this->details->sync($path, $yearId, $grade, $dryRun);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'Simulación. Nada se escribió. Usa --write para aplicar.'
            : 'Fichas de 2026-2027 actualizadas.');
        $this->line('Archivo: '.$path);
        $this->line('Ciclo: '.$summary['academic_year_label'].' | grado '.$summary['grade']);
        $this->line('Filas del directorio: '.$summary['rows']);
        $this->line('Inscritos en 2026-2027 emparejados: '.$summary['matched']);
        $this->line($dryRun
            ? 'Alumnos que se actualizarían: '.$this->touchedCount($summary)
            : 'Alumnos actualizados: '.$summary['applied']);
        $this->line('No están en 2026-2027 u omitidos: '.count($summary['skipped']));
        $this->line('Errores: '.count($summary['errors']));
        $this->comment('No se toca CURP, fecha de nacimiento, género ni edad.');
        $this->comment('El Excel gana en teléfonos y correos. Un tutor distinto se agrega sin quitar al anterior.');

        $this->printContacts('Teléfonos tomados del Excel', $summary['phone_changes']);
        $this->printContacts('Correos tomados del Excel', $summary['email_changes']);
        $this->printTutors($summary['tutors_added']);

        if ($summary['address_fills'] !== []) {
            $this->newLine();
            $this->line('Domicilios completados porque faltaban ('.count($summary['address_fills']).').');
        }

        $this->printIssues('Omitidos', $summary['skipped']);
        $this->printIssues('Errores', $summary['errors']);

        return $summary['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function resolveYearId(int $yearId): int
    {
        if ($yearId > 0) {
            $year = AcademicYear::query()->find($yearId);
            if ($year && (string) $year->year_start === '2026' && (string) $year->year_end === '2027') {
                return (int) $year->id;
            }

            return 0;
        }

        $year = AcademicYear::query()
            ->where('year_start', '2026')
            ->where('year_end', '2027')
            ->first();

        return $year ? (int) $year->id : 0;
    }

    private function gradeName(string $raw): string
    {
        $raw = trim($raw);
        if (in_array($raw, ['2', '2°', 'segundo'], true)) {
            return '2°';
        }
        if (in_array($raw, ['3', '3°', 'tercero'], true)) {
            return '3°';
        }

        return '1°';
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    private function touchedCount(array $summary): int
    {
        $names = [];
        foreach (array_merge($summary['phone_changes'], $summary['email_changes'], $summary['tutors_added'], $summary['address_fills']) as $row) {
            $names[$row['student'] ?? ''] = true;
        }

        return count(array_filter(array_keys($names)));
    }

    /**
     * @param  list<array{student: string, who: string, from: string, to: string}>  $rows
     */
    private function printContacts(string $title, array $rows): void
    {
        $this->newLine();
        $this->warn($title.' ('.count($rows).'):');
        if ($rows === []) {
            $this->line($title === 'Correos tomados del Excel'
                ? '  Ninguno. Si el Excel no trae columna de correo, no hay nada que copiar.'
                : '  Ninguno.');

            return;
        }

        foreach ($rows as $index => $row) {
            $this->line(sprintf(
                '  %d. %s | %s | %s → %s',
                $index + 1,
                $row['student'],
                $row['who'],
                $row['from'],
                $row['to']
            ));
        }
    }

    /**
     * @param  list<array{student: string, tutor: string, curp: string, kinship: string, phone?: string, email?: string}>  $rows
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
                ($row['phone'] ?? '') !== '' ? ' | tel '.$row['phone'] : '',
                ($row['email'] ?? '') !== '' ? ' | '.$row['email'] : ''
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
        foreach (array_slice($rows, 0, 40) as $row) {
            $this->line('  fila '.$row['row'].' '.$row['curp'].' '.$row['name'].' — '.$row['reason']);
        }
        if (count($rows) > 40) {
            $this->line('  … y '.(count($rows) - 40).' más.');
        }
    }
}
