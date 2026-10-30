<?php

namespace App\Console\Commands\temporal;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Profile;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Temporary: among active students of 2026-2027, if maternal surname is empty
 * and paternal has exactly two words, move the second into second_last_name.
 * Both surnames filled → skip; 3+ paternal words → list for manual review.
 */
class SplitCompoundLastNames extends Command
{
    protected $signature = 'profiles:split-compound-last-names
                            {--year= : ID del ciclo 2026-2027}
                            {--dry-run : Solo lista; no escribe}
                            {--yes : Aplica sin preguntar (después de listar)}';

    protected $description = '[temporal] Dividir apellido paterno de 2 palabras → materno (solo inscritos activos 2026-2027)';

    public function handle(): int
    {
        $year = $this->resolveYear();
        if (! $year) {
            $this->error('No se encontró el ciclo 2026-2027.');

            return self::FAILURE;
        }

        $this->info('Ciclo '.$year->year_start.'-'.$year->year_end.' | solo alumnos con inscripción activa.');

        $toSplit = [];
        $tooManyWords = [];
        $skippedBothFilled = 0;
        $skippedEmptyPaternal = 0;
        $skippedSingleWord = 0;
        $scanned = 0;

        Profile::query()
            ->whereHas('student.enrollments', function ($query) use ($year) {
                $query->where('academic_year_id', $year->id)
                    ->where('status', EnrollmentStatus::Active);
            })
            ->orderBy('id')
            ->select(['id', 'first_name', 'last_name', 'second_last_name', 'national_id'])
            ->chunkById(500, function ($profiles) use (
                &$toSplit,
                &$tooManyWords,
                &$skippedBothFilled,
                &$skippedEmptyPaternal,
                &$skippedSingleWord,
                &$scanned
            ) {
                foreach ($profiles as $profile) {
                    $scanned++;
                    $paternal = trim((string) $profile->last_name);
                    $maternal = trim((string) $profile->second_last_name);

                    if ($paternal !== '' && $maternal !== '') {
                        $skippedBothFilled++;

                        continue;
                    }

                    if ($paternal === '') {
                        $skippedEmptyPaternal++;

                        continue;
                    }

                    $words = preg_split('/\s+/u', $paternal, -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    $count = count($words);

                    if ($count === 1) {
                        $skippedSingleWord++;

                        continue;
                    }

                    if ($count === 2) {
                        $toSplit[] = [
                            'id' => $profile->id,
                            'name' => trim(($profile->first_name ?? '').' '.$paternal),
                            'curp' => (string) ($profile->national_id ?? ''),
                            'paternal_now' => $paternal,
                            'word1' => $words[0],
                            'word2' => $words[1],
                            'maternal_will_be' => $words[1],
                        ];

                        continue;
                    }

                    $tooManyWords[] = [
                        'id' => $profile->id,
                        'name' => trim(($profile->first_name ?? '').' '.$paternal),
                        'curp' => (string) ($profile->national_id ?? ''),
                        'paternal' => $paternal,
                        'words' => $count,
                    ];
                }
            });

        $this->newLine();
        $this->table(
            ['Concepto', 'Cantidad'],
            [
                ['Alumnos activos 2026-2027 revisados', $scanned],
                ['Ya tienen paterno y materno (descartados)', $skippedBothFilled],
                ['Sin apellido paterno', $skippedEmptyPaternal],
                ['Paterno con 1 sola palabra', $skippedSingleWord],
                ['Paterno con exactamente 2 palabras (a dividir)', count($toSplit)],
                ['Paterno con más de 2 palabras (manual)', count($tooManyWords)],
            ]
        );

        $this->newLine();
        $this->warn('=== Se dividirán (2 palabras en paterno, materno vacío) — '.count($toSplit).' ===');
        if ($toSplit === []) {
            $this->line('  Ninguno.');
        } else {
            $this->table(
                ['ID', 'Nombre', 'CURP', 'Paterno actual', 'Quedará paterno', '2ª palabra → materno'],
                array_map(fn (array $row) => [
                    $row['id'],
                    $row['name'],
                    $row['curp'] !== '' ? $row['curp'] : '—',
                    $row['paternal_now'],
                    $row['word1'],
                    $row['maternal_will_be'],
                ], $toSplit)
            );
        }

        $this->newLine();
        $this->warn('=== Más de 2 palabras en paterno (no se tocan) — '.count($tooManyWords).' ===');
        if ($tooManyWords === []) {
            $this->line('  Ninguno.');
        } else {
            $this->table(
                ['ID', 'Nombre', 'CURP', 'Paterno', '# palabras'],
                array_map(fn (array $row) => [
                    $row['id'],
                    $row['name'],
                    $row['curp'] !== '' ? $row['curp'] : '—',
                    $row['paternal'],
                    $row['words'],
                ], $tooManyWords)
            );
        }

        if ($this->option('dry-run')) {
            $this->newLine();
            $this->comment('Dry-run: no se escribió nada. Quita --dry-run para pedir confirmación y aplicar.');

            return self::SUCCESS;
        }

        if ($toSplit === []) {
            $this->newLine();
            $this->info('Nada que aplicar. Revisa a mano los de más de 2 palabras si hace falta.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->warn('Se actualizarían '.count($toSplit).' perfil(es). Los de más de 2 palabras no se tocan.');

        if (! $this->option('yes') && ! $this->confirm('¿Continuar con la división?', false)) {
            $this->comment('Cancelado. No se escribió nada.');

            return self::SUCCESS;
        }

        $applied = 0;
        DB::transaction(function () use ($toSplit, &$applied) {
            foreach ($toSplit as $row) {
                $updated = Profile::query()->whereKey($row['id'])->update([
                    'last_name' => $row['word1'],
                    'second_last_name' => $row['word2'],
                ]);
                $applied += $updated > 0 ? 1 : 0;
            }
        });

        $this->newLine();
        $this->info("Actualizados: {$applied}");

        return self::SUCCESS;
    }

    private function resolveYear(): ?AcademicYear
    {
        $query = AcademicYear::query()
            ->where('year_start', '2026')
            ->where('year_end', '2027');

        $yearId = (int) $this->option('year');
        if ($yearId > 0) {
            $query->whereKey($yearId);
        }

        return $query->first();
    }
}
