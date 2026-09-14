<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Services\AssignBulkGhWorkshopsService;
use Illuminate\Console\Command;

class AssignBulkGhWorkshops extends Command
{
    protected $signature = 'workshops:bulk-gh
                            {--year= : ID del ciclo escolar}
                            {--write : Persiste. Sin este flag es simulación}
                            {--force : Permite exceder cupo}';

    protected $description = 'Asigna Informática a grupos G y Diseño a grupos H. No pisa manual ni inherited.';

    public function __construct(
        private readonly AssignBulkGhWorkshopsService $service
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $yearId = (int) $this->option('year');
        if ($yearId <= 0) {
            $yearId = (int) AcademicYear::query()->where('is_active', true)->value('id');
        }
        if ($yearId <= 0) {
            $this->error('Indica --year o activa un ciclo escolar.');

            return self::FAILURE;
        }

        $dryRun = ! $this->option('write');

        try {
            $summary = $this->service->run(
                academicYearId: $yearId,
                dryRun: $dryRun,
                force: (bool) $this->option('force'),
            );
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info($dryRun ? 'Simulación (dry-run). Usa --write para persistir.' : 'Asignación G/H aplicada.');
        $this->line('candidatos: '.$summary['summary']['total_candidates']);
        $this->line('asignados: '.$summary['summary']['assigned']);
        $this->line('protegidos: '.$summary['summary']['protected']);
        $this->line('sin cupo: '.$summary['summary']['over_capacity']);

        return self::SUCCESS;
    }
}
