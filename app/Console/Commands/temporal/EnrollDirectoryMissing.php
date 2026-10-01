<?php

namespace App\Console\Commands\temporal;

use App\Models\AcademicYear;
use Illuminate\Console\Command;
use Throwable;

class EnrollDirectoryMissing extends Command
{
    protected $signature = 'admissions:enroll-directory-missing
                            {--grade=todos : Grado de la lista: 2, 3 o todos}
                            {--write : Inscribe a los que faltan}';

    protected $description = 'Inscribe en 2026-2027 a quien está en el directorio de 2° o 3° y aún no está inscrito: alta tardía, promoción desde el grado anterior o repetición. Sin --write solo simula.';

    private const FILE_HINTS = [
        '2°' => 'SEGUNDO',
        '3°' => 'TERCER',
    ];

    public function __construct(
        private readonly EnrollDirectoryMissingService $service
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
            '2', '2°', 'segundo', 'segundos' => ['2°'],
            '3', '3°', 'tercero', 'terceros' => ['3°'],
            '', 'todos', 'all' => ['2°', '3°'],
            default => [],
        };
        if ($grades === []) {
            $this->error('Grado inválido. Usa 2, 3 o todos.');

            return self::FAILURE;
        }

        $dryRun = ! $this->option('write');
        $this->info($dryRun
            ? 'Simulación. Nada se escribió. Usa --write para aplicar.'
            : 'Inscribiendo en 2026-2027 a los que faltan.');

        $failed = false;
        foreach ($grades as $grade) {
            $path = $this->defaultFile($grade);
            $this->newLine();
            $this->line(str_repeat('=', 72));
            if ($path === '') {
                $this->warn('Grado '.$grade.': no se encontró su directorio en storage/app/temp/listasExcel.');

                continue;
            }

            try {
                $summary = $this->service->enroll($path, $yearId, $grade, $dryRun);
            } catch (Throwable $exception) {
                $this->error('Grado '.$grade.': '.$exception->getMessage());
                $failed = true;

                continue;
            }

            $this->info('Grado '.$summary['grade'].' | ciclo '.$summary['academic_year_label'].' | '.basename($path).' | hoja '.$summary['sheet']);
            $this->line('Filas del directorio: '.$summary['rows']);
            $this->line('Ya inscritos: '.$summary['already']);
            $this->line('Altas tardías: '.count($summary['created']));
            $this->line('Promovidos: '.count($summary['promoted']));
            $this->line('Repiten grado: '.count($summary['retained']));
            if (! $dryRun) {
                $this->line('Aplicados: '.$summary['applied']);
            }
            $this->line('Sin inscribir: '.count($summary['skipped']));
            $this->line('Errores: '.count($summary['errors']));

            $this->printPeople('Altas tardías (ingreso nuevo)', $summary['created']);
            $this->printPeople('Promovidos desde el grado anterior', $summary['promoted']);
            $this->printPeople('Repiten el grado', $summary['retained']);
            $this->printIssues('Sin inscribir', $summary['skipped']);
            $this->printIssues('Errores', $summary['errors']);
            $failed = $failed || $summary['errors'] !== [];
        }

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
     * @param  list<array{row: string, curp: string, name: string, group: string, workshop: string, note: string}>  $rows
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
            $this->line(sprintf('  %d. %s | %s | %s | %s', $index + 1, $row['name'], $row['curp'], $row['group'], $row['workshop']));
            if ($row['note'] !== '') {
                $this->line('     '.$row['note']);
            }
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
