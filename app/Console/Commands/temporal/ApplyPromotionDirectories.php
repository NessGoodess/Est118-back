<?php

namespace App\Console\Commands\temporal;

use Illuminate\Console\Command;
use Throwable;

class ApplyPromotionDirectories extends Command
{
    protected $signature = 'reenrollment:apply-directories
                            {--second= : Ruta al directorio de 2°}
                            {--third= : Ruta al directorio de 3°}
                            {--write : Coloca alumnos en el periodo de promoción y actualiza sus datos}';

    protected $description = 'Promueve 2° y 3° desde los directorios del Excel, dentro del periodo de reinscripción abierto. Sin --write solo simula.';

    public function __construct(
        private readonly ApplyPromotionDirectoriesService $directories
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        @ini_set('memory_limit', '512M');

        $second = (string) ($this->option('second') ?: $this->findFile('2°', 'segundo'));
        $third = (string) ($this->option('third') ?: $this->findFile('tercer'));
        if ($second === '' || $third === '') {
            $this->error('No se encontraron los directorios de 2° y 3° en storage/app/temp/listasExcel.');

            return self::FAILURE;
        }

        $dryRun = ! $this->option('write');

        try {
            $summary = $this->directories->apply($second, $third, $dryRun);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun
            ? 'Simulación del periodo '.$summary['period_name'].'. Nada se escribió. Usa --write para aplicarlo.'
            : 'Promoción aplicada en el periodo '.$summary['period_name'].'.');
        $this->line('2°: '.$second);
        $this->line('3°: '.$third);
        $this->line('Filas: '.$summary['rows']);
        $this->line($dryRun ? 'Se colocarían: '.$summary['placed'] : 'Colocados: '.$summary['applied']);
        $this->line('Sin alumno: '.count($summary['manual_adds']));
        $this->line('CURP parecida: '.count($summary['curp_comparisons']));
        $this->line('Edades que no concuerdan: '.count($summary['age_mismatches']));
        $this->line('Egresan (3° aprobado y fuera de los directorios): '.count($summary['graduated']));
        $this->line('Pendientes de promover (no están en ningún directorio): '.count($summary['needs_review']));
        $this->line('Registros de prueba eliminados: '.count($summary['removed_demo']));
        $this->line('Errores: '.count($summary['errors']));

        $grades = [];
        $workshops = [];
        foreach ($summary['planned'] as $row) {
            $key = $row['grade'].' '.$row['group'];
            $grades[$key] = ($grades[$key] ?? 0) + 1;
            $label = $row['workshop'] ?: 'sin taller en el Excel';
            $workshops[$label] = ($workshops[$label] ?? 0) + 1;
        }
        if ($grades !== []) {
            ksort($grades);
            $this->line('Grupos: '.collect($grades)->map(fn ($count, $name) => $name.'='.$count)->implode(', '));
        }
        if ($workshops !== []) {
            $this->line('Talleres: '.collect($workshops)->map(fn ($count, $name) => $name.'='.$count)->implode(', '));
        }
        $this->comment('El directorio de 2° no trae tecnología; esos alumnos quedan en su grupo sin taller nuevo.');
        $this->comment('La fecha del Excel solo sustituye la de la base cuando coincide con la CURP.');
        $this->comment('Quien está en el directorio de 3° pasa o repite 3°. Quien está en el de 2° pasa o repite 2°. Quien no está en ninguno queda pendiente de promover. El 3° aprobado que no está en ninguna lista egresa.');

        $this->printManual($summary['manual_adds']);
        $this->printCurps($summary['curp_comparisons']);
        $this->printAges($summary['age_mismatches']);
        $this->printClosure('Egresan. La inscripción del ciclo actual queda concluida', $summary['graduated']);
        $this->printClosure('Pendientes de promover. No se movieron', $summary['needs_review']);
        $this->printClosure($dryRun ? 'Registro de prueba que se eliminará' : 'Registro de prueba eliminado', $summary['removed_demo']);
        $this->printIssues('Errores', $summary['errors']);

        return $summary['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }

    private function findFile(string ...$needles): string
    {
        $directory = storage_path('app/temp/listasExcel');
        $matches = glob($directory.DIRECTORY_SEPARATOR.'*.xlsx') ?: [];
        foreach ($matches as $file) {
            $name = mb_strtolower(basename($file));
            foreach ($needles as $needle) {
                if (str_contains($name, mb_strtolower($needle))) {
                    return $file;
                }
            }
        }

        return '';
    }

    /**
     * @param  list<array{name: string, curp: string, grade: string, group: string, tech: string}>  $rows
     */
    private function printManual(array $rows): void
    {
        $this->newLine();
        $this->warn('Alumnos de los directorios que no están en la base ('.count($rows).'). Agregarlos a mano:');
        if ($rows === []) {
            $this->line('  Ninguno.');

            return;
        }
        foreach ($rows as $index => $row) {
            $this->line(sprintf(
                '  %d. %s %s | CURP %s | grupo %s | %s',
                $index + 1,
                $row['grade'],
                $row['name'],
                $row['curp'],
                $row['group'] !== '' ? $row['group'] : '—',
                $row['tech'] !== '' ? $row['tech'] : 'sin taller'
            ));
        }
    }

    /**
     * @param  list<array{name: string, excel_curp: string, db_curp: string, decision: string}>  $rows
     */
    private function printCurps(array $rows): void
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
    private function printAges(array $rows): void
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
     * @param  list<array{name: string, curp: string, grade: string, group: string, decision: string}>  $rows
     */
    private function printClosure(string $title, array $rows): void
    {
        $this->newLine();
        $this->warn($title.' ('.count($rows).'):');
        if ($rows === []) {
            $this->line('  Ninguno.');

            return;
        }
        foreach ($rows as $index => $row) {
            $place = trim(($row['grade'] !== '' ? $row['grade'].' ' : '').$row['name']);
            $group = $row['group'] !== '' ? ' | grupo '.$row['group'] : '';
            $this->line(sprintf(
                '  %d. %s | CURP %s%s | %s',
                $index + 1,
                $place,
                $row['curp'] !== '' ? $row['curp'] : '—',
                $group,
                $row['decision']
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
            $this->line('  fila '.$row['row'].' '.$row['curp'].' '.$row['name'].' — '.$row['reason']);
        }
    }
}
