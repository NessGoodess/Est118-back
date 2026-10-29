<?php

namespace App\Console\Commands;

use App\Services\People\StudentGuardianPrimaryService;
use Illuminate\Console\Command;

/**
 * Temporary ops command: run once after deploy to repair legacy pivots,
 * then remove when no longer needed. Ongoing safety lives in
 * StudentGuardianPrimaryService (store/update/detach).
 */
class EnsureGuardianPrimaries extends Command
{
    protected $signature = 'guardians:ensure-primary
                            {--dry-run : List students that would change without writing}';

    protected $description = '[temporal] Reparar tutores principales faltantes o duplicados';

    public function handle(StudentGuardianPrimaryService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun
            ? 'Dry-run: no se escribirá nada.'
            : 'Reparando tutores principales…');

        $stats = $service->backfillAll($dryRun);

        $this->newLine();
        $this->table(
            ['Resultado', 'Cantidad'],
            [
                [$dryRun ? 'Sin principal (promovería)' : 'Sin principal (promovido)', $stats['missing']],
                [$dryRun ? 'Varios principales (dejaría uno)' : 'Varios principales (deduplicado)', $stats['multiple']],
                ['Ya correctos', $stats['ok']],
            ]
        );

        if ($stats['changes'] === []) {
            $this->info('Ningún alumno requiere cambios.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->info($dryRun
            ? 'Alumnos que cambiarían:'
            : 'Alumnos con cambios:');

        $this->table(
            ['ID', 'Alumno', 'Acción', 'Tutor principal (id)'],
            array_map(
                fn (array $row) => [
                    $row['student_id'],
                    $row['name'],
                    $row['action'] === 'missing'
                        ? ($dryRun ? 'promovería principal' : 'promovió principal')
                        : ($dryRun ? 'dejaría un principal' : 'deduplicó principales'),
                    $row['primary_guardian_id'],
                ],
                $stats['changes']
            )
        );

        return self::SUCCESS;
    }
}
