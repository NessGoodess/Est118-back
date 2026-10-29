<?php

namespace App\Console\Commands\Temporal;

use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Temporary ops command: copy a child's address onto guardians that have none.
 * Remove when the one-off repair is done.
 *
 * Rules:
 * - Guardian has no profile.address_id
 * - Collect distinct address_id from linked students that have one
 * - Exactly 1 unique address → assign (or would assign in --dry-run)
 * - 2+ unique addresses → skip; listed at the end for manual decision
 * - 0 student addresses → skip (nothing to copy)
 */
class BackfillGuardianAddressesFromStudents extends Command
{
    protected $signature = 'guardians:backfill-address-from-students
                            {--dry-run : List what would change without writing}';

    protected $description = '[temporal] Completar domicilio de tutores desde el de sus hijos a cargo';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $this->info($dryRun
            ? 'Dry-run: no se escribirá nada.'
            : 'Aplicando domicilios de hijos a tutores sin domicilio…');

        $guardians = Guardian::query()
            ->whereHas('profile', fn ($q) => $q->whereNull('address_id'))
            ->with([
                'profile:id,first_name,last_name,second_last_name,address_id',
                'students.profile:id,first_name,last_name,second_last_name,address_id',
                'students.currentEnrollment.classGroup.gradeLevel',
            ])
            ->orderBy('id')
            ->get();

        $wouldApply = [];
        $applied = [];
        $ambiguous = [];
        $noChildAddress = [];

        foreach ($guardians as $guardian) {
            $profile = $guardian->profile;
            if (! $profile || $profile->address_id) {
                continue;
            }

            $children = $this->childRows($guardian);
            $withAddress = $children->filter(fn (array $c) => $c['address_id'] !== null);
            $row = [
                'guardian_id' => (int) $guardian->id,
                'name' => $profile->fullName(),
                'students' => $children
                    ->map(fn (array $c) => $this->formatChild($c))
                    ->implode('; '),
                'address_ids' => $withAddress->pluck('address_id')->unique()->values()->all(),
            ];

            $uniqueCount = count($row['address_ids']);

            if ($uniqueCount === 0) {
                $noChildAddress[] = $row;

                continue;
            }

            if ($uniqueCount >= 2) {
                $ambiguous[] = $row;

                continue;
            }

            $addressId = (int) $row['address_ids'][0];
            $row['chosen_address_id'] = $addressId;

            if ($dryRun) {
                $wouldApply[] = $row;

                continue;
            }

            DB::table('profiles')
                ->where('id', $profile->id)
                ->whereNull('address_id')
                ->update(['address_id' => $addressId]);

            $applied[] = $row;
        }

        $changed = $dryRun ? $wouldApply : $applied;

        $this->newLine();
        $this->table(
            ['Resultado', 'Cantidad'],
            [
                [$dryRun ? 'Tomarían domicilio del hijo (1 único)' : 'Domicilio aplicado (1 único)', count($changed)],
                ['Sin domicilio en hijos (omitidos)', count($noChildAddress)],
                ['2+ domicilios distintos (manual)', count($ambiguous)],
                ['Tutores sin domicilio revisados', $guardians->count()],
            ]
        );

        if ($changed !== []) {
            $this->newLine();
            $this->info($dryRun
                ? 'Tutores que tomarían el domicilio del hijo:'
                : 'Tutores actualizados:');
            $this->table(
                ['Tutor ID', 'Tutor', 'address_id', 'Tutorados (grado/grupo)'],
                array_map(
                    fn (array $r) => [
                        $r['guardian_id'],
                        $r['name'],
                        $r['chosen_address_id'],
                        $r['students'] !== '' ? $r['students'] : '—',
                    ],
                    $changed
                )
            );
        }

        if ($noChildAddress !== []) {
            $this->newLine();
            $this->warn('Tutores sin domicilio y sin domicilio en hijos (nada que copiar):');
            $this->table(
                ['Tutor ID', 'Tutor', 'Tutorados (grado/grupo)'],
                array_map(
                    fn (array $r) => [
                        $r['guardian_id'],
                        $r['name'],
                        $r['students'] !== '' ? $r['students'] : '—',
                    ],
                    $noChildAddress
                )
            );
        }

        if ($ambiguous !== []) {
            $this->newLine();
            $this->error('Tutores con 2 o más domicilios distintos en hijos — decidir manualmente:');
            $this->table(
                ['Tutor ID', 'Tutor', 'address_ids', 'Tutorados (grado/grupo)'],
                array_map(
                    fn (array $r) => [
                        $r['guardian_id'],
                        $r['name'],
                        implode(', ', $r['address_ids']),
                        $r['students'],
                    ],
                    $ambiguous
                )
            );
        }

        if ($changed === [] && $ambiguous === [] && $noChildAddress === []) {
            $this->info('Nada que hacer: no hay tutores sin domicilio.');
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, array{student_id: int, student_name: string, grade_group: string, address_id: ?int}>
     */
    private function childRows(Guardian $guardian): Collection
    {
        return $guardian->students
            ->filter(fn (Student $student) => $student->profile !== null)
            ->map(fn (Student $student) => [
                'student_id' => (int) $student->id,
                'student_name' => $student->profile->fullName(),
                'grade_group' => $this->gradeGroup($student),
                'address_id' => $student->profile->address_id
                    ? (int) $student->profile->address_id
                    : null,
            ])
            ->values();
    }

    /**
     * @param  array{student_id: int, student_name: string, grade_group: string, address_id: ?int}  $child
     */
    private function formatChild(array $child): string
    {
        $place = $child['grade_group'] !== '' ? $child['grade_group'] : 'sin grupo';
        $addr = $child['address_id'] !== null ? "addr {$child['address_id']}" : 'sin domicilio';

        return "{$place} · {$child['student_name']} (#{$child['student_id']}, {$addr})";
    }

    private function gradeGroup(Student $student): string
    {
        $group = $student->currentEnrollment?->classGroup;

        return trim(($group?->gradeLevel?->name ?? '').' '.($group?->name ?? ''));
    }
}
