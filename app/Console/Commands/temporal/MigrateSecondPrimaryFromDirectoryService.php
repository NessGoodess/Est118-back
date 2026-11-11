<?php

namespace App\Console\Commands\temporal;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Address;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Guardian;
use App\Models\Profile;
use App\Models\Student;
use App\Services\People\StudentGuardianPrimaryService;
use App\Support\Curp;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Replace 2° primary tutors from the directory Excel.
 * Match is student CURP only. Never edits a tutor linked to 1°.
 * Skips the row when the Excel tutor is this student's secondary.
 */
class MigrateSecondPrimaryFromDirectoryService
{
    public function __construct(
        private readonly SyncDirectoryDetailsService $directory,
        private readonly StudentGuardianPrimaryService $roles,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function preview(string $path, int $academicYearId): array
    {
        return $this->run($path, $academicYearId, write: false);
    }

    /**
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

        $second = $this->findGrade('2');
        $first = $this->findGrade('1');
        if (! $second) {
            throw new RuntimeException('No existe el grado 2° en el catálogo.');
        }

        [$sheetName, $rows] = $this->directory->readRows($path);
        $enrollments = $this->enrollments($year->id, (int) $second->id);
        $byCurp = [];
        foreach ($enrollments as $enrollment) {
            $curp = Curp::normalize((string) $enrollment->student?->profile?->national_id);
            if ($curp !== '') {
                $byCurp[$curp][] = $enrollment;
            }
        }

        $plans = [];
        $skippedSecondary = [];
        $pendingFirst = [];
        $unmatched = [];
        $problems = [];
        $usedEnrollmentIds = [];
        $applied = 0;

        foreach ($rows as $row) {
            $studentCurp = Curp::normalize($row['curp'] ?? '');
            $found = $studentCurp !== '' ? ($byCurp[$studentCurp] ?? []) : [];
            if (count($found) !== 1) {
                $unmatched[] = [
                    'excel_row' => $row['row'],
                    'student' => $row['student_name'],
                    'curp' => $studentCurp,
                    'reason' => $studentCurp === ''
                        ? 'La fila no trae CURP del alumno.'
                        : (count($found) === 0
                            ? 'No hay inscrito activo de 2°  con esa CURP.'
                            : 'La CURP del alumno está repetida en varios inscritos de 2°.'),
                ];

                continue;
            }

            /** @var Enrollment $enrollment */
            $enrollment = $found[0];
            $usedEnrollmentIds[(int) $enrollment->id] = true;
            $student = $enrollment->student;
            $decision = $this->decide($student, $row, $year->id, $first?->id);

            if ($decision['status'] === 'skip_secondary') {
                $skippedSecondary[] = $decision['report'];

                continue;
            }
            if ($decision['status'] === 'pending_first') {
                $pendingFirst[] = $decision['report'];

                continue;
            }
            if ($decision['status'] === 'problem') {
                $problems[] = $decision['report'];

                continue;
            }

            $plans[] = $decision['plan'];
            if (! $write) {
                continue;
            }

            try {
                DB::transaction(function () use ($decision) {
                    ($decision['write'])();
                });
                $applied++;
            } catch (Throwable $exception) {
                $problems[] = [
                    'excel_row' => $row['row'],
                    'student' => $row['student_name'],
                    'curp' => $studentCurp,
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
            'plans' => $plans,
            'skipped_secondary' => $skippedSecondary,
            'pending_first' => $pendingFirst,
            'unmatched' => $unmatched,
            'only_in_db' => $this->onlyInDatabase($enrollments, $usedEnrollmentIds),
            'problems' => $problems,
            'applied' => $write ? $applied : 0,
        ];
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Enrollment>  $enrollments
     * @param  array<int, true>  $usedEnrollmentIds
     * @return list<array{excel_row: string, student: string, curp: string, reason: string}>
     */
    private function onlyInDatabase($enrollments, array $usedEnrollmentIds): array
    {
        $rows = [];
        foreach ($enrollments as $enrollment) {
            if (isset($usedEnrollmentIds[(int) $enrollment->id])) {
                continue;
            }
            $profile = $enrollment->student?->profile;
            $rows[] = [
                'excel_row' => '—',
                'student' => $this->personName($profile),
                'curp' => Curp::normalize((string) $profile?->national_id),
                'reason' => 'Inscrito activo en 2° y no aparece en el Excel. Grupo '.($enrollment->classGroup?->name ?: '—').'.',
            ];
        }

        usort($rows, fn (array $a, array $b) => strcmp($a['student'], $b['student']));

        return $rows;
    }

    /**
     * @param  array<string, string>  $row
     * @return array{status: string, report?: array<string, string>, plan?: array<string, mixed>, write?: callable}
     */
    private function decide(Student $student, array $row, int $yearId, ?int $firstGradeId): array
    {
        $student->loadMissing('profile.address', 'guardians.profile');
        $studentCurp = Curp::normalize((string) $student->profile?->national_id);
        $excelCurp = $this->usableTutorCurp((string) ($row['g_curp'] ?? ''), $studentCurp);
        $primary = $student->guardians->first(fn (Guardian $g) => (bool) ($g->pivot->is_primary ?? false));
        $secondary = $student->guardians->first(fn (Guardian $g) => (bool) ($g->pivot->is_secondary ?? false));

        $base = [
            'excel_row' => $row['row'],
            'student' => $this->personName($student->profile),
            'curp' => $studentCurp,
            'group' => (string) ($row['group'] ?? ''),
            'excel_tutor' => $this->excelTutorName($row),
        ];

        $byCurp = $excelCurp !== null
            ? Guardian::query()->with('profile')->whereHas(
                'profile',
                fn ($q) => $q->where('national_id', $excelCurp)
            )->first()
            : null;

        if ($byCurp && $this->linkedToFirstGrade($byCurp, $yearId, $firstGradeId)) {
            return [
                'status' => 'pending_first',
                'report' => $base + [
                    'reason' => 'La CURP del tutor ya pertenece a un tutor de 1° ('.$this->personName($byCurp->profile).'). No se modifica.',
                ],
            ];
        }

        if ($primary && $this->linkedToFirstGrade($primary, $yearId, $firstGradeId) && (! $byCurp || (int) $byCurp->id === (int) $primary->id)) {
            return [
                'status' => 'pending_first',
                'report' => $base + [
                    'reason' => 'El tutor principal actual también es tutor de 1°. No se modifica.',
                ],
            ];
        }

        if ($secondary && $byCurp && (int) $secondary->id === (int) $byCurp->id) {
            return [
                'status' => 'skip_secondary',
                'report' => $base + [
                    'reason' => 'La CURP del Excel es el tutor secundario de este alumno. Se omite.',
                ],
            ];
        }

        if ($excelCurp === null && trim((string) ($row['g_curp'] ?? '')) !== '') {
            return [
                'status' => 'problem',
                'report' => $base + [
                    'reason' => 'CURP de tutor inválida o igual a la del alumno ('.$row['g_curp'].').',
                ],
            ];
        }

        $mode = 'overwrite_primary';
        $target = $primary;
        if ($byCurp) {
            $mode = (int) ($primary?->id) === (int) $byCurp->id ? 'overwrite_primary' : 'update_matched_curp';
            $target = $byCurp;
        } elseif (! $primary) {
            $mode = 'create_primary';
            $target = null;
        }

        $before = $target?->profile ? $this->snapshot($target, $student) : '— sin tutor principal —';
        $after = $this->excelSnapshot($row);
        $completeness = $this->excelCompleteness($row, $excelCurp);

        $plan = [
            'excel_row' => $row['row'],
            'student_id' => $student->id,
            'student' => $base['student'],
            'group' => $base['group'],
            'mode' => $mode,
            'before' => $before,
            'after' => $after,
            'completeness' => $completeness['status'],
            'missing' => $completeness['missing'],
            'also_linked' => $target ? $this->otherStudentsLabel($target, (int) $student->id) : '',
        ];

        $write = function () use ($student, $row, $excelCurp, $target, $mode) {
            $this->applyRow($student, $row, $excelCurp, $target, $mode);
        };

        return ['status' => 'apply', 'plan' => $plan, 'write' => $write];
    }

    private function applyRow(Student $student, array $row, ?string $excelCurp, ?Guardian $target, string $mode): void
    {
        $student->loadMissing('profile.address', 'guardians');
        $kinship = trim((string) ($row['kinship'] ?? ''));
        $kinship = $kinship === '' ? null : $kinship;
        $phone = $this->digits((string) ($row['phone'] ?? ''));

        if ($mode === 'create_primary' || ! $target?->profile) {
            $profile = Profile::query()->create([
                'first_name' => trim((string) $row['g_name']) ?: 'NO_ESPECIFICADO',
                'last_name' => trim((string) $row['g_paterno']) ?: 'NO_ESPECIFICADO',
                'second_last_name' => trim((string) $row['g_materno']) ?: null,
                'national_id' => $excelCurp,
                'phone_number' => $phone,
                'gender' => $excelCurp ? ($this->genderFromCurp($excelCurp) ?? 'O') : 'O',
                'birth_date' => $excelCurp ? $this->dateFromCurp($excelCurp) : null,
            ]);
            $target = Guardian::query()->create([
                'profile_id' => $profile->id,
                'Kinship' => $kinship,
            ]);
        } else {
            $profile = $target->profile;
            $profile->first_name = trim((string) $row['g_name']) ?: $profile->first_name;
            $profile->last_name = trim((string) $row['g_paterno']) ?: $profile->last_name;
            $profile->second_last_name = trim((string) $row['g_materno']) ?: null;
            if ($phone !== null) {
                $profile->phone_number = $phone;
            }
            if ($excelCurp !== null && Curp::normalize((string) $profile->national_id) !== $excelCurp) {
                $taken = Profile::query()->where('national_id', $excelCurp)->whereKeyNot($profile->id)->exists();
                if ($taken) {
                    throw new RuntimeException('La CURP '.$excelCurp.' ya pertenece a otro perfil.');
                }
                $profile->national_id = $excelCurp;
                $birth = $this->dateFromCurp($excelCurp);
                $gender = $this->genderFromCurp($excelCurp);
                if ($birth !== null) {
                    $profile->birth_date = $birth;
                }
                if ($gender !== null) {
                    $profile->gender = $gender;
                }
            }
            $profile->save();
            if ($kinship !== null) {
                $target->update(['Kinship' => $kinship]);
            }
        }

        $this->shareAddress($student, $target->profile, $row);

        DB::table('guardian_student')
            ->where('student_id', $student->id)
            ->where('guardian_id', '!=', $target->id)
            ->update(['is_primary' => false]);

        $already = $student->guardians()->where('guardians.id', $target->id)->exists();
        $pivot = [
            'is_primary' => true,
            'is_secondary' => false,
        ];
        if ($kinship !== null) {
            $pivot['relationship'] = $kinship;
        }
        if ($already) {
            $student->guardians()->updateExistingPivot($target->id, $pivot);
        } else {
            $student->guardians()->attach($target->id, $pivot + ['sort_order' => 0]);
        }

        $this->roles->normalizeRoles((int) $student->id);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function shareAddress(Student $student, Profile $tutorProfile, array $row): void
    {
        $incoming = [
            'street_type' => trim((string) ($row['street_type'] ?? '')),
            'street_name' => trim((string) ($row['street'] ?? '')),
            'house_number' => trim((string) (($row['exterior'] ?? '') !== '' ? $row['exterior'] : ($row['interior'] ?? ''))),
            'unit_number' => ($row['exterior'] ?? '') !== '' && ($row['interior'] ?? '') !== '' ? trim((string) $row['interior']) : null,
            'neighborhood_type' => trim((string) ($row['settlement_type'] ?? '')),
            'neighborhood_name' => trim((string) ($row['settlement'] ?? '')),
            'city' => trim((string) ($row['city'] ?? '')),
        ];
        $hasAddress = $incoming['street_name'] !== '' || $incoming['neighborhood_name'] !== '';
        if (! $hasAddress) {
            if ($student->profile?->address_id) {
                $tutorProfile->address_id = $student->profile->address_id;
                $tutorProfile->save();
            }

            return;
        }

        $student->loadMissing('profile.address');
        $address = $student->profile?->address;
        $patch = array_filter($incoming, fn ($value) => $value !== null && $value !== '');
        if ($address) {
            $address->update($patch);
        } else {
            // Excel almost never brings C.P.; keep 00000 as the known placeholder.
            $address = Address::query()->create(array_merge([
                'street_type' => 'CALLE',
                'street_name' => 'NO_ESPECIFICADO',
                'house_number' => 'SN',
                'neighborhood_type' => 'COLONIA',
                'neighborhood_name' => 'NO_ESPECIFICADO',
                'postal_code' => '00000',
                'city' => 'NO_ESPECIFICADO',
                'state' => 'Oaxaca',
            ], $patch, ['postal_code' => '00000']));
            $student->profile->address_id = $address->id;
            $student->profile->save();
        }

        $tutorProfile->address_id = $address->id;
        $tutorProfile->save();
    }

    private function linkedToFirstGrade(Guardian $guardian, int $yearId, ?int $firstGradeId): bool
    {
        if (! $firstGradeId) {
            return false;
        }

        return Enrollment::query()
            ->where('academic_year_id', $yearId)
            ->where('status', EnrollmentStatus::Active)
            ->whereHas('classGroup', fn ($q) => $q->where('grade_level_id', $firstGradeId))
            ->whereHas('student.guardians', fn ($q) => $q->where('guardians.id', $guardian->id))
            ->exists();
    }

    private function otherStudentsLabel(Guardian $guardian, int $exceptStudentId): string
    {
        $guardian->loadMissing('students.profile');
        $names = $guardian->students
            ->filter(fn (Student $student) => (int) $student->id !== $exceptStudentId)
            ->map(fn (Student $student) => $this->personName($student->profile))
            ->filter()
            ->values();

        return $names->isEmpty() ? '' : $names->implode(', ');
    }

    private function snapshot(Guardian $guardian, Student $student): string
    {
        $profile = $guardian->profile;
        $pivot = $student->guardians->firstWhere('id', $guardian->id)?->pivot;
        $rel = trim((string) ($pivot->relationship ?? $guardian->Kinship ?? ''));

        return trim(implode(' | ', array_filter([
            $this->personName($profile),
            Curp::normalize((string) $profile?->national_id) ?: 'sin CURP',
            (string) ($profile?->phone_number ?: 'sin tel'),
            $rel !== '' ? $rel : 'sin parentesco',
        ])));
    }

    /**
     * @param  array<string, string>  $row
     */
    private function excelSnapshot(array $row): string
    {
        return trim(implode(' | ', array_filter([
            $this->excelTutorName($row) ?: 'sin nombre',
            Curp::normalize((string) ($row['g_curp'] ?? '')) ?: 'sin CURP',
            $this->digits((string) ($row['phone'] ?? '')) ?: 'sin tel',
            trim((string) ($row['kinship'] ?? '')) ?: 'sin parentesco',
        ])));
    }

    /**
     * @param  array<string, string>  $row
     * @return array{status: 'completo'|'parcial', missing: list<string>}
     */
    private function excelCompleteness(array $row, ?string $excelCurp): array
    {
        $checks = [
            'nombre tutor' => trim((string) ($row['g_name'] ?? '')) !== '',
            'apellido paterno' => trim((string) ($row['g_paterno'] ?? '')) !== '',
            'apellido materno' => trim((string) ($row['g_materno'] ?? '')) !== '',
            'CURP tutor' => $excelCurp !== null,
            'teléfono' => $this->digits((string) ($row['phone'] ?? '')) !== null,
            'parentesco' => trim((string) ($row['kinship'] ?? '')) !== '',
            'tipo de vialidad' => trim((string) ($row['street_type'] ?? '')) !== '',
            'calle / vialidad' => trim((string) ($row['street'] ?? '')) !== '',
            'número exterior' => trim((string) ($row['exterior'] ?? '')) !== '',
            'tipo de asentamiento' => trim((string) ($row['settlement_type'] ?? '')) !== '',
            'colonia / asentamiento' => trim((string) ($row['settlement'] ?? '')) !== '',
            'municipio' => trim((string) ($row['city'] ?? '')) !== '',
        ];

        $missing = [];
        foreach ($checks as $label => $ok) {
            if (! $ok) {
                $missing[] = $label;
            }
        }

        // C.P. is intentionally not required: we always store 00000 when creating.
        return [
            'status' => $missing === [] ? 'completo' : 'parcial',
            'missing' => $missing,
        ];
    }

    /**
     * @return \Illuminate\Support\Collection<int, Enrollment>
     */
    private function enrollments(int $yearId, int $gradeId)
    {
        return Enrollment::query()
            ->where('academic_year_id', $yearId)
            ->where('status', EnrollmentStatus::Active)
            ->whereHas('classGroup', fn ($query) => $query->where('grade_level_id', $gradeId))
            ->with(['student.profile.address', 'student.guardians.profile', 'classGroup'])
            ->get()
            ->filter(fn (Enrollment $enrollment) => $enrollment->student?->profile)
            ->values();
    }

    private function usableTutorCurp(string $curp, string $studentCurp): ?string
    {
        $curp = Curp::normalize($curp);
        if ($curp === '' || preg_match(Curp::PATTERN, $curp) !== 1) {
            return null;
        }
        if ($studentCurp !== '' && $curp === $studentCurp) {
            return null;
        }
        if (str_starts_with($curp, 'NO_ESPECIFICADO') || str_starts_with($curp, 'TUT')) {
            return null;
        }

        return $curp;
    }

    /**
     * Grade names in some DBs lost the ° glyph and became "2??".
     */
    private function findGrade(string $number): ?GradeLevel
    {
        foreach ([$number.'°', $number.'º', $number.'??', $number.'.', $number] as $name) {
            $found = GradeLevel::query()->where('name', $name)->first();
            if ($found) {
                return $found;
            }
        }

        return GradeLevel::query()
            ->where('name', 'like', $number.'%')
            ->orderBy('id')
            ->first();
    }

    /**
     * @param  array<string, string>  $row
     */
    private function excelTutorName(array $row): string
    {
        return trim(($row['g_name'] ?? '').' '.($row['g_paterno'] ?? '').' '.($row['g_materno'] ?? ''));
    }

    private function personName(?Profile $profile): string
    {
        if (! $profile) {
            return '';
        }

        return trim($profile->first_name.' '.$profile->last_name.' '.($profile->second_last_name ?? ''));
    }

    private function digits(string $value): ?string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';

        return strlen($digits) >= 8 ? $digits : null;
    }

    private function dateFromCurp(string $curp): ?string
    {
        return Curp::derive($curp)['birth_date'] ?? null;
    }

    private function genderFromCurp(string $curp): ?string
    {
        return Curp::derive($curp)['gender'] ?? null;
    }
}
