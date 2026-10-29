<?php

namespace App\Console\Commands\temporal;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Guardian;
use App\Models\Profile;
use App\Models\Student;
use App\Services\People\StudentGuardianPrimaryService;
use App\Support\Curp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Temporal: update primary tutor CURP / phone / relationship for 2° from the directory Excel.
 */
class SyncSecondGradePrimaryTutorsService
{
    public const DEFAULT_RELATIVE = 'temp/listasExcel/DIRECTORIO 2° 26-27.xlsx';

    public function __construct(
        private readonly SyncDirectoryDetailsService $directory,
        private readonly StudentGuardianPrimaryService $primaries,
    ) {}

    public static function defaultPath(): string
    {
        return storage_path('app/'.self::DEFAULT_RELATIVE);
    }

    /**
     * @return array{
     *   dry_run: bool,
     *   sheet: string,
     *   academic_year_label: string,
     *   excel_rows: int,
     *   db_students: int,
     *   matched: list<array<string, mixed>>,
     *   unmatched_excel: list<array<string, mixed>>,
     *   unmatched_db: list<array<string, mixed>>,
     *   applied: int,
     *   errors: list<array{student: string, reason: string}>
     * }
     */
    public function preview(string $path, int $academicYearId): array
    {
        return $this->run($path, $academicYearId, write: false);
    }

    /**
     * Apply planned changes for matched rows that have updates.
     *
     * @return array<string, mixed>
     */
    public function apply(string $path, int $academicYearId): array
    {
        return $this->run($path, $academicYearId, write: true);
    }

    /**
     * @return array<string, mixed>
     */
    private function run(string $path, int $academicYearId, bool $write): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('No se encontró el Excel: '.$path);
        }

        $year = AcademicYear::query()->find($academicYearId);
        if (! $year) {
            throw new RuntimeException('No existe el ciclo escolar indicado.');
        }

        $grade = GradeLevel::query()->where('name', '2°')->first();
        if (! $grade) {
            throw new RuntimeException('No existe el grado 2° en el catálogo.');
        }

        [$sheetName, $rows] = $this->directory->readRows($path);
        $enrollments = $this->periodEnrollments($year->id, $grade->id);
        $classified = $this->classify($rows, $enrollments);

        $matched = [];
        $errors = [];
        $applied = 0;

        foreach ($classified['matched'] as $item) {
            /** @var Enrollment $enrollment */
            $enrollment = $item['enrollment'];
            $student = $enrollment->student;
            $plan = $this->planPrimaryTutorUpdate($student, $item['row']);

            $matched[] = [
                'excel_row' => $item['row']['row'],
                'method' => $item['method'],
                'student_id' => $student->id,
                'student' => $this->profileName($student->profile),
                'group' => (string) ($enrollment->classGroup?->name ?? $item['row']['group']),
                'excel_tutor' => $this->excelTutorName($item['row']),
                'db_tutor' => $plan['tutor_name'],
                'changes' => $plan['changes'],
                'problems' => $plan['problems'],
                'will_update' => $plan['writes'] !== [],
            ];

            if (! $write || $plan['writes'] === []) {
                continue;
            }

            try {
                DB::transaction(function () use ($plan) {
                    foreach ($plan['writes'] as $writeFn) {
                        $writeFn();
                    }
                });
                $applied++;
            } catch (Throwable $exception) {
                $errors[] = [
                    'student' => $this->profileName($student->profile),
                    'reason' => $exception->getMessage(),
                ];
            }
        }

        return [
            'dry_run' => ! $write,
            'sheet' => $sheetName,
            'academic_year_label' => trim($year->year_start.'-'.$year->year_end),
            'excel_rows' => count($rows),
            'db_students' => $enrollments->count(),
            'matched' => $matched,
            'unmatched_excel' => $classified['unmatched_excel'],
            'unmatched_db' => $classified['unmatched_db'],
            'applied' => $write ? $applied : 0,
            'errors' => $errors,
        ];
    }

    /**
     * @return Collection<int, Enrollment>
     */
    private function periodEnrollments(int $yearId, int $gradeId): Collection
    {
        return Enrollment::query()
            ->where('academic_year_id', $yearId)
            ->where('status', EnrollmentStatus::Active)
            ->whereHas('classGroup', fn ($query) => $query->where('grade_level_id', $gradeId))
            ->with(['student.profile', 'student.guardians.profile', 'classGroup'])
            ->get()
            ->filter(fn (Enrollment $enrollment) => $enrollment->student?->profile)
            ->values();
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  Collection<int, Enrollment>  $enrollments
     * @return array{
     *   matched: list<array{row: array<string, string>, enrollment: Enrollment, method: string}>,
     *   unmatched_excel: list<array<string, mixed>>,
     *   unmatched_db: list<array<string, mixed>>
     * }
     */
    private function classify(array $rows, Collection $enrollments): array
    {
        $used = [];
        $matched = [];
        $unmatchedExcel = [];

        $byCurp = [];
        foreach ($enrollments as $enrollment) {
            $curp = strtoupper(trim((string) $enrollment->student->profile->national_id));
            if ($curp !== '') {
                $byCurp[$curp][] = $enrollment;
            }
        }

        $pending = [];
        foreach ($rows as $index => $row) {
            $found = array_values(array_filter(
                $byCurp[$row['curp']] ?? [],
                fn (Enrollment $enrollment) => ! isset($used[$enrollment->id])
            ));
            if ($row['curp'] !== '' && count($found) === 1) {
                $used[$found[0]->id] = true;
                $matched[] = ['row' => $row, 'enrollment' => $found[0], 'method' => 'curp'];

                continue;
            }
            if ($row['curp'] !== '' && count($found) > 1) {
                $unmatchedExcel[] = $this->excelIssue($row, 'CURP del alumno repetida en varios inscritos de 2°.');

                continue;
            }
            $pending[$index] = $row;
        }

        foreach ($pending as $index => $row) {
            $candidates = [];
            if ($row['curp'] !== '') {
                foreach ($enrollments as $enrollment) {
                    if (isset($used[$enrollment->id])) {
                        continue;
                    }
                    $curp = strtoupper(trim((string) $enrollment->student->profile->national_id));
                    if ($curp === '' || abs(strlen($curp) - strlen($row['curp'])) > 2 || levenshtein($curp, $row['curp']) > 2) {
                        continue;
                    }
                    if (! $this->namesMatch($row['student_name'], $this->profileName($enrollment->student->profile))) {
                        continue;
                    }
                    $candidates[] = $enrollment;
                }
            }
            if (count($candidates) === 1) {
                $used[$candidates[0]->id] = true;
                $matched[] = ['row' => $row, 'enrollment' => $candidates[0], 'method' => 'curp_parecida'];
                unset($pending[$index]);
            }
        }

        foreach ($pending as $index => $row) {
            $candidates = [];
            foreach ($enrollments as $enrollment) {
                if (isset($used[$enrollment->id])) {
                    continue;
                }
                if (! $this->sameName($row['student_name'], $this->profileName($enrollment->student->profile))) {
                    continue;
                }
                if ($row['group'] !== '' && ! $this->sameGroup($row['group'], (string) ($enrollment->classGroup?->name ?? ''))) {
                    continue;
                }
                $candidates[] = $enrollment;
            }

            if (count($candidates) === 1) {
                $used[$candidates[0]->id] = true;
                $matched[] = ['row' => $row, 'enrollment' => $candidates[0], 'method' => 'nombre_grupo'];
                unset($pending[$index]);

                continue;
            }

            if ($row['group'] !== '' && count($candidates) === 0) {
                foreach ($enrollments as $enrollment) {
                    if (isset($used[$enrollment->id])) {
                        continue;
                    }
                    if ($this->sameName($row['student_name'], $this->profileName($enrollment->student->profile))) {
                        $candidates[] = $enrollment;
                    }
                }
                if (count($candidates) === 1) {
                    $used[$candidates[0]->id] = true;
                    $matched[] = ['row' => $row, 'enrollment' => $candidates[0], 'method' => 'nombre'];
                    unset($pending[$index]);

                    continue;
                }
            }

            $reason = count($candidates) > 1
                ? 'Nombre ambiguo: coincide con '.count($candidates).' alumnos de 2°.'
                : ($row['student_name'] === '' && $row['curp'] === ''
                    ? 'Fila vacía o sin nombre/CURP de alumno.'
                    : 'No se encontró en 2° por CURP ni por nombre+grupo.');
            $unmatchedExcel[] = $this->excelIssue($row, $reason);
            unset($pending[$index]);
        }

        $unmatchedDb = $enrollments
            ->reject(fn (Enrollment $enrollment) => isset($used[$enrollment->id]))
            ->map(fn (Enrollment $enrollment) => [
                'student_id' => $enrollment->student_id,
                'student' => $this->profileName($enrollment->student->profile),
                'curp' => strtoupper(trim((string) $enrollment->student->profile->national_id)),
                'group' => (string) ($enrollment->classGroup?->name ?? ''),
                'reason' => 'Inscrito en 2° pero no aparece (o no emparejó) en el Excel.',
            ])
            ->sortBy(fn (array $row) => $row['group'].' '.$row['student'])
            ->values()
            ->all();

        usort($matched, fn (array $a, array $b) => strcmp(
            ($a['enrollment']->classGroup?->name ?? '').' '.$this->profileName($a['enrollment']->student->profile),
            ($b['enrollment']->classGroup?->name ?? '').' '.$this->profileName($b['enrollment']->student->profile),
        ));

        return [
            'matched' => $matched,
            'unmatched_excel' => $unmatchedExcel,
            'unmatched_db' => $unmatchedDb,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return array{
     *   tutor_name: string,
     *   changes: list<string>,
     *   problems: list<string>,
     *   writes: list<callable>
     * }
     */
    private function planPrimaryTutorUpdate(Student $student, array $row): array
    {
        $student->loadMissing('guardians.profile');

        $primary = $student->guardians->first(fn (Guardian $guardian) => (bool) ($guardian->pivot->is_primary ?? false));
        $willPromote = false;
        if (! $primary) {
            $primary = $student->guardians->sortBy(fn (Guardian $g) => (int) ($g->pivot->sort_order ?? 0))->first();
            $willPromote = $primary !== null;
        }

        $plan = [
            'tutor_name' => $primary?->profile ? $this->profileName($primary->profile) : '—',
            'changes' => [],
            'problems' => [],
            'writes' => [],
        ];

        if (! $primary?->profile) {
            $plan['problems'][] = 'El alumno no tiene tutor enlazado.';

            return $plan;
        }

        if ($willPromote) {
            $plan['changes'][] = 'marcar como principal a '.$plan['tutor_name'];
            $plan['writes'][] = function () use ($student) {
                $this->primaries->ensureExactlyOne((int) $student->id);
            };
        }

        $profile = $primary->profile;
        $phone = $this->digits($row['phone']);
        if ($row['phone'] !== '' && $phone === null) {
            $plan['problems'][] = 'Teléfono del Excel inválido ('.$row['phone'].').';
        }
        if ($phone !== null && $this->digits((string) $profile->phone_number) !== $phone) {
            $from = (string) ($profile->phone_number ?: '—');
            $plan['changes'][] = 'teléfono: '.$from.' → '.$phone;
            $plan['writes'][] = function () use ($profile, $phone) {
                $profile->phone_number = $phone;
                $profile->save();
            };
        }

        $kinship = trim($row['kinship']);
        $currentKinship = trim((string) ($primary->pivot->relationship ?? $primary->Kinship ?? ''));
        if ($kinship !== '' && $this->normalizeName($kinship) !== $this->normalizeName($currentKinship)) {
            $plan['changes'][] = 'parentesco: '.($currentKinship !== '' ? $currentKinship : '—').' → '.$kinship;
            $plan['writes'][] = function () use ($primary, $student, $kinship) {
                $primary->update(['Kinship' => $kinship]);
                $student->guardians()->updateExistingPivot($primary->id, [
                    'relationship' => $kinship,
                ]);
            };
        }

        $excelCurp = Curp::normalize($row['g_curp'] ?? '');
        $dbCurp = Curp::normalize((string) $profile->national_id);
        $studentCurp = Curp::normalize((string) $student->profile?->national_id);

        if ($excelCurp !== '' && $excelCurp !== $dbCurp) {
            if (preg_match(Curp::PATTERN, $excelCurp) !== 1) {
                $plan['problems'][] = 'CURP de tutor en Excel inválida ('.$row['g_curp'].').';
            } elseif ($studentCurp !== '' && $excelCurp === $studentCurp) {
                $plan['problems'][] = 'La CURP del tutor en Excel es la del alumno; no se cambia.';
            } else {
                $owner = Profile::query()
                    ->where('national_id', $excelCurp)
                    ->whereKeyNot($profile->id)
                    ->first();
                if ($owner) {
                    $plan['problems'][] = 'CURP de tutor '.$excelCurp.' ya pertenece a '.$this->profileName($owner).'; no se cambia.';
                } else {
                    $plan['changes'][] = 'CURP tutor: '.($dbCurp !== '' ? $dbCurp : '—').' → '.$excelCurp;
                    $plan['writes'][] = function () use ($profile, $excelCurp) {
                        $profile->national_id = $excelCurp;
                        $birth = $this->dateFromCurp($excelCurp);
                        if ($birth !== null) {
                            $profile->birth_date = $birth;
                        }
                        $gender = $this->genderFromCurp($excelCurp);
                        if ($gender !== null) {
                            $profile->gender = $gender;
                        }
                        $profile->save();
                    };
                }
            }
        }

        return $plan;
    }

    /**
     * @param  array<string, string>  $row
     * @return array{excel_row: string, student: string, curp: string, group: string, excel_tutor: string, reason: string}
     */
    private function excelIssue(array $row, string $reason): array
    {
        return [
            'excel_row' => $row['row'],
            'student' => $row['student_name'],
            'curp' => $row['curp'],
            'group' => $row['group'],
            'excel_tutor' => $this->excelTutorName($row),
            'reason' => $reason,
        ];
    }

    /**
     * @param  array<string, string>  $row
     */
    private function excelTutorName(array $row): string
    {
        return trim($row['g_name'].' '.$row['g_paterno'].' '.$row['g_materno']);
    }

    private function profileName(?Profile $profile): string
    {
        if (! $profile) {
            return '';
        }

        return trim($profile->first_name.' '.$profile->last_name);
    }

    private function sameGroup(string $excel, string $db): bool
    {
        $normalize = fn (string $value) => preg_replace('/[^A-Z0-9]/', '', mb_strtoupper($value, 'UTF-8')) ?? '';

        return $normalize($excel) !== '' && $normalize($excel) === $normalize($db);
    }

    private function sameName(string $left, string $right): bool
    {
        $leftTokens = $this->nameTokens($left);
        $rightTokens = $this->nameTokens($right);
        sort($leftTokens);
        sort($rightTokens);

        return $leftTokens !== [] && count($leftTokens) >= 2 && $leftTokens === $rightTokens;
    }

    private function namesMatch(string $left, string $right): bool
    {
        $leftTokens = $this->nameTokens($left);
        $rightTokens = $this->nameTokens($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }
        $shorter = count($leftTokens) <= count($rightTokens) ? $leftTokens : $rightTokens;
        $longer = count($leftTokens) <= count($rightTokens) ? $rightTokens : $leftTokens;

        return array_diff($shorter, $longer) === [] && count($shorter) >= 2;
    }

    /**
     * @return list<string>
     */
    private function nameTokens(string $value): array
    {
        $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtoupper($value, 'UTF-8')) ?: mb_strtoupper($value, 'UTF-8');
        $parts = preg_split('/[^A-Z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_filter(
            $parts,
            fn (string $token) => strlen($token) >= 3 && ! in_array($token, ['DEL', 'LAS', 'LOS'], true)
        ));
    }

    private function digits(string $value): ?string
    {
        $parts = preg_split('/[,;\/]|\s-\s|\s+y\s+|\s+o\s+/iu', $value) ?: [$value];
        foreach ($parts as $part) {
            $digits = preg_replace('/\D/', '', $part) ?? '';
            if (strlen($digits) === 10) {
                return $digits;
            }
        }

        return null;
    }

    private function normalizeName(string $value): string
    {
        $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtoupper($value, 'UTF-8')) ?: $value;

        return preg_replace('/[^A-Z0-9]/', '', $folded) ?? '';
    }

    private function dateFromCurp(string $curp): ?string
    {
        if (! preg_match('/^[A-Z]{4}(\d{2})(\d{2})(\d{2})[HM][A-Z]{5}([A-Z0-9])/', $curp, $matches)) {
            return null;
        }

        $year = (int) $matches[1];
        $year += ctype_alpha($matches[4]) ? 2000 : 1900;
        $month = (int) $matches[2];
        $day = (int) $matches[3];

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    private function genderFromCurp(string $curp): ?string
    {
        return match (substr($curp, 10, 1)) {
            'H' => 'M',
            'M' => 'F',
            default => null,
        };
    }
}
