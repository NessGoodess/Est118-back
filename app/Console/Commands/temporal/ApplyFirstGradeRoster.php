<?php

namespace App\Console\Commands\temporal;

use Illuminate\Console\Command;
use Throwable;

class ApplyFirstGradeRoster extends Command
{
    protected $signature = 'admissions:apply-first-grade-roster
                            {--file= : Ruta al Excel DIRECTORIO PRIMEROS}
                            {--year= : ID del ciclo destino. Por defecto el ciclo de reinscripción abierto}
                            {--write : Actualiza preinscripciones, inscribe tardíos y asigna grupo y taller}';

    protected $description = 'Aplica el directorio de 1°: convierte preinscripciones, da de alta tardía a quienes no estaban e inscribe grupo y taller. Sin --write solo simula.';

    public function __construct(
        private readonly ApplyFirstGradeRosterService $roster
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        @ini_set('memory_limit', '512M');

        $yearId = ApplyFirstGradeRosterService::resolveYearId((int) $this->option('year'));
        if ($yearId <= 0) {
            $this->error('Indica --year o abre un periodo de reinscripción / ciclo activo.');

            return self::FAILURE;
        }

        $path = (string) ($this->option('file') ?: ApplyFirstGradeRosterService::resolveDefaultFile());
        if ($path === '') {
            $this->error('No se encontró el Excel. Pásalo con --file.');

            return self::FAILURE;
        }

        $dryRun = ! $this->option('write');

        try {
            $summary = $this->roster->apply($path, $yearId, $dryRun);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'Simulación. Nada se escribió. Usa --write para aplicarlo una vez.'
            : 'Lista aplicada.');
        $this->line('Archivo: '.$path);
        $this->line('Ciclo destino: '.$summary['academic_year_label'].' (id '.$summary['academic_year_id'].')');
        $this->line('Filas del directorio: '.$summary['rows']);
        $this->line('En preinscripción: '.$summary['in_pre']);
        $this->line('En listas y no en preinscripción: '.$summary['not_in_pre_count']);
        $this->line('  Ya eran alumnos (se inscriben en destino): '.count($summary['placed_existing']));
        $this->line('  Altas tardías nuevas: '.count($summary['created_late']));
        $this->line('Ya en 1° destino (pasados): '.count($summary['already_in_first']));
        $this->line('Por convertir desde preinscripción: '.count($summary['converted']));
        $this->line('CURP corregida por nombre idéntico: '.$summary['near_matches']);
        $this->line($dryRun
            ? 'Se procesarían: '.count($summary['planned'])
            : 'Procesados: '.$summary['applied']);
        $this->line('1° destino que no aparecen en las listas: '.count($summary['dest_first_not_on_list']));
        $this->line('1° origen que no aparecen en las listas: '.count($summary['origin_first_not_on_list']));
        $this->line('Omitidos: '.count($summary['skipped']));
        $this->line('Errores: '.count($summary['errors']));

        $workshops = [];
        $groups = [];
        foreach ($summary['planned'] as $row) {
            $workshops[$row['workshop']] = ($workshops[$row['workshop']] ?? 0) + 1;
            $groups[$row['group']] = ($groups[$row['group']] ?? 0) + 1;
        }
        if ($groups !== []) {
            $this->line('Grupos: '.collect($groups)->map(fn ($count, $name) => $name.'='.$count)->implode(', '));
        }
        if ($workshops !== []) {
            $this->line('Talleres: '.collect($workshops)->map(fn ($count, $name) => $name.'='.$count)->implode(', '));
        }

        $this->comment('La fecha de nacimiento del Excel no se copia: se conserva la de la preinscripción o la de la CURP en altas nuevas.');
        $this->comment('Ofimática es taller interno y no aparece en el formulario de preinscripción.');

        $this->printPeople('En listas y no en preinscripción', $summary['manual_adds']);
        $this->printPeople('Altas tardías nuevas', $summary['created_late']);
        $this->printPeople('Ya eran alumnos y se inscriben en 1° destino', $summary['placed_existing']);
        $this->printPeople('Ya en 1° destino (pasados)', $summary['already_in_first']);
        $this->printPeople('1° destino que no aparecen en las listas', $summary['dest_first_not_on_list']);
        $this->printPeople('1° origen que no aparecen en las listas', $summary['origin_first_not_on_list']);
        $this->printCurpComparisons($summary['curp_comparisons']);
        $this->printAgeMismatches($summary['age_mismatches']);

        $otherSkips = array_values(array_filter(
            $summary['skipped'],
            fn (array $row) => ! str_contains($row['reason'], 'No hay preinscripción')
        ));
        $this->printIssues('Otros omitidos', $otherSkips);
        $this->printIssues('Errores', $summary['errors']);

        return $summary['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function printPeople(string $title, array $rows): void
    {
        $this->newLine();
        $this->warn($title.' ('.count($rows).'):');
        if ($rows === []) {
            $this->line('  Ninguno.');

            return;
        }

        foreach ($rows as $index => $row) {
            $this->line(sprintf(
                '  %d. %s | CURP %s | grupo %s | %s',
                $index + 1,
                $row['name'] ?? '—',
                $row['curp'] ?? '—',
                ($row['group'] ?? '') !== '' ? $row['group'] : '—',
                $row['tech'] ?? ($row['reason'] ?? '')
            ));
        }
    }

    /**
     * @param  list<array{name: string, excel_curp: string, db_curp: string, decision: string}>  $rows
     */
    private function printCurpComparisons(array $rows): void
    {
        $this->newLine();
        $this->warn('CURP distinta entre el Excel y la base ('.count($rows).'):');
        if ($rows === []) {
            $this->line('  Ninguna.');

            return;
        }

        foreach ($rows as $row) {
            $this->line(str_repeat('-', 60));
            $this->line($row['name']);
            $this->line(str_repeat('-', 60));
            $this->line(sprintf('%-28s | %s', 'Excel', 'Base'));
            $this->line(str_repeat('-', 60));
            $this->line(sprintf('%-28s | %s', $row['excel_curp'], $row['db_curp']));
            $this->line($row['decision']);
        }
        $this->line(str_repeat('-', 60));
    }

    /**
     * @param  list<array{name: string, excel_birth: string, excel_age: string, curp_birth: string, curp_age: string, kept: string}>  $rows
     */
    private function printAgeMismatches(array $rows): void
    {
        $this->newLine();
        $this->warn('Edades del Excel que no concuerdan con su CURP ('.count($rows).'):');
        if ($rows === []) {
            $this->line('  Ninguna.');

            return;
        }

        foreach ($rows as $row) {
            $this->line(str_repeat('-', 72));
            $this->line($row['name']);
            $this->line(str_repeat('-', 72));
            $this->line(sprintf('%-34s | %s', 'Excel', 'Según la CURP'));
            $this->line(str_repeat('-', 72));
            $this->line(sprintf('%-34s | %s', 'Fecha '.$row['excel_birth'], 'Fecha '.$row['curp_birth']));
            $this->line(sprintf('%-34s | %s', 'Edad '.$row['excel_age'], 'Edad '.$row['curp_age']));
            $this->line($row['kept']);
        }
        $this->line(str_repeat('-', 72));
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
