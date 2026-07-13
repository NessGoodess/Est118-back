<?php

namespace App\Console\Commands;

use App\Services\EnrollmentPromotionService;
use Illuminate\Console\Command;

class PromoteAcademicYear extends Command
{
    /**
     * The name and signature of the console command.
     *
     * Example:
     * php artisan academic-year:promote --from=1 --to=2 --dry-run
     */
    protected $signature = 'academic-year:promote
                            {--from= : ID del ciclo origen}
                            {--to= : ID del ciclo destino}
                            {--dry-run : Simula el proceso sin guardar cambios}';

    /**
     * The console command description.
     */
    protected $description = 'Promueve inscripciones al siguiente ciclo conservando grupo según reglas institucionales.';

    public function __construct(
        private readonly EnrollmentPromotionService $promotionService
    ) {
        parent::__construct();
    }

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $from = (int) $this->option('from');
        $to = (int) $this->option('to');
        $dryRun = (bool) $this->option('dry-run');

        if ($from <= 0 || $to <= 0) {
            $this->error('Debes enviar --from y --to con IDs válidos.');
            return self::FAILURE;
        }

        try {
            $summary = $this->promotionService->promote($from, $to, $dryRun);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->info($dryRun ? 'Simulación completada.' : 'Promoción completada.');
        $this->newLine();
        $this->table(
            ['Métrica', 'Valor'],
            [
                ['Ciclo origen', (string) $summary['from_academic_year_id']],
                ['Ciclo destino', (string) $summary['to_academic_year_id']],
                ['Procesados', (string) $summary['processed']],
                ['Promovidos', (string) $summary['promoted']],
                ['Reprobados retenidos', (string) $summary['retained']],
                ['Egresados', (string) $summary['graduated']],
                ['Errores', (string) count($summary['errors'])],
            ]
        );

        if (! empty($summary['errors'])) {
            $this->newLine();
            $this->warn('Se detectaron errores:');
            foreach ($summary['errors'] as $error) {
                $this->line(
                    "- enrollment_id={$error['enrollment_id']} student_id={$error['student_id']} {$error['message']}"
                );
            }
        }

        return self::SUCCESS;
    }
}
