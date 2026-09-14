<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Services\ImportSchedulesFromExcelService;
use Illuminate\Console\Command;

class ImportSchedulesFromExcel extends Command
{
    protected $signature = 'schedules:import-excel
                            {--year= : ID del ciclo escolar}
                            {--grupos= : Ruta al Excel HORARIOS GRUPOS}
                            {--docentes= : Ruta al Excel HORARIOS DOCENTES}
                            {--write : Persiste. Sin este flag es simulación}
                            {--dry-run : Legacy: simula (es el default)}';

    protected $description = 'Importa horarios de grupo y taller desde los Excel institucionales. No asigna taller al alumno.';

    public function __construct(
        private readonly ImportSchedulesFromExcelService $importer
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        @ini_set('memory_limit', '512M');

        $yearId = (int) $this->option('year');
        if ($yearId <= 0) {
            $yearId = (int) AcademicYear::query()->where('is_active', true)->value('id');
        }
        if ($yearId <= 0) {
            $this->error('Indica --year o activa un ciclo escolar.');

            return self::FAILURE;
        }

        $root = dirname(base_path());
        $grupos = (string) ($this->option('grupos') ?: $root.DIRECTORY_SEPARATOR.'HORARIOS GRUPOS 25 - 26.xlsx');
        $docentes = $this->option('docentes');
        $docentesPath = $docentes
            ? (string) $docentes
            : $root.DIRECTORY_SEPARATOR.'HORARIOS DOCENTES 25 - 26.xlsx';

        $dryRun = ! $this->option('write');

        try {
            $summary = $this->importer->import(
                academicYearId: $yearId,
                gruposPath: $grupos,
                docentesPath: is_file($docentesPath) ? $docentesPath : null,
                dryRun: $dryRun,
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Simulación (dry-run). Usa --write para persistir.' : 'Importación aplicada.');
        $this->line('planned: '.$summary['planned']);
        $this->line('created: '.$summary['created']);
        $this->line('skipped: '.$summary['skipped']);
        $this->line('teachers_assigned: '.($summary['teachers_assigned'] ?? 0));

        if (! empty($summary['workshop_sheets'])) {
            $this->line('Hojas de taller:');
            foreach ($summary['workshop_sheets'] as $sheet) {
                $this->line('  - '.$sheet);
            }
        }

        if (! empty($summary['unmatched_subjects'])) {
            $this->warn('Materias no reconocidas: '.implode(', ', array_slice($summary['unmatched_subjects'], 0, 25)));
        }

        $this->comment('Los Excel de docentes no traen columna Viernes; esos slots de taller no se importan.');

        return self::SUCCESS;
    }
}
