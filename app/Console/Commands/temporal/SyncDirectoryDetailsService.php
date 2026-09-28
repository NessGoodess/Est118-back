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
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use RuntimeException;
use Throwable;

class SyncDirectoryDetailsService
{
    /**
     * @return array<string, mixed>
     */
    public function sync(string $path, int $academicYearId, string $gradeName = '1°', bool $dryRun = true): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('No se encontró el Excel de directorio.');
        }

        $year = AcademicYear::query()->find($academicYearId);
        if (! $year) {
            throw new RuntimeException('No existe el ciclo escolar indicado.');
        }
        if ((string) $year->year_start !== '2026' || (string) $year->year_end !== '2027') {
            throw new RuntimeException('Este comando solo actualiza fichas del ciclo 2026-2027.');
        }

        $grade = GradeLevel::query()->where('name', $gradeName)->first();
        if (! $grade) {
            throw new RuntimeException('No existe el grado '.$gradeName.' en el catálogo.');
        }

        $rows = $this->readRows($path);
        $enrollments = $this->periodEnrollments($year->id, $grade->id);
        $matches = $this->matchRows($rows, $enrollments);

        $phoneChanges = [];
        $emailChanges = [];
        $tutorsAdded = [];
        $addressFills = [];
        $skipped = [];
        $errors = [];
        $applied = 0;

        foreach ($matches as $match) {
            if ($match['enrollment'] === null) {
                $skipped[] = $this->issue($match, $match['reason'] !== '' ? $match['reason'] : 'No está inscrito en 2026-2027.');

                continue;
            }

            try {
                $changes = $this->planChanges($match['enrollment']->student, $match['row']);
                foreach ($changes['phones'] as $change) {
                    $phoneChanges[] = $change;
                }
                foreach ($changes['emails'] as $change) {
                    $emailChanges[] = $change;
                }
                foreach ($changes['tutors'] as $change) {
                    $tutorsAdded[] = $change;
                }
                foreach ($changes['addresses'] as $change) {
                    $addressFills[] = $change;
                }

                if ($dryRun || $changes['writes'] === []) {
                    continue;
                }

                DB::transaction(function () use ($changes) {
                    foreach ($changes['writes'] as $write) {
                        $write();
                    }
                });
                $applied++;
            } catch (Throwable $exception) {
                $errors[] = $this->issue($match, $exception->getMessage());
            }
        }

        return [
            'dry_run' => $dryRun,
            'academic_year_id' => $year->id,
            'academic_year_label' => trim($year->year_start.'-'.$year->year_end),
            'grade' => $gradeName,
            'rows' => count($rows),
            'matched' => count(array_filter($matches, fn (array $match) => $match['enrollment'] !== null)),
            'applied' => $dryRun ? 0 : $applied,
            'phone_changes' => $phoneChanges,
            'email_changes' => $emailChanges,
            'tutors_added' => $tutorsAdded,
            'address_fills' => $addressFills,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @return Collection<string, Enrollment>
     */
    private function periodEnrollments(int $yearId, int $gradeId): Collection
    {
        return Enrollment::query()
            ->where('academic_year_id', $yearId)
            ->where('status', EnrollmentStatus::Active)
            ->whereHas('classGroup', fn ($query) => $query->where('grade_level_id', $gradeId))
            ->with(['student.profile.address', 'student.guardians.profile', 'classGroup'])
            ->get()
            ->filter(fn (Enrollment $enrollment) => $enrollment->student?->profile)
            ->keyBy(fn (Enrollment $enrollment) => strtoupper(trim((string) $enrollment->student->profile->national_id)));
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  Collection<string, Enrollment>  $enrollments
     * @return list<array{row: array<string, string>, enrollment: ?Enrollment, reason: string}>
     */
    private function matchRows(array $rows, Collection $enrollments): array
    {
        $used = [];
        $matches = [];
        $pendingNear = [];

        foreach ($rows as $row) {
            if ($row['curp'] === '') {
                $matches[] = ['row' => $row, 'enrollment' => null, 'reason' => 'La fila no trae CURP.'];

                continue;
            }

            $found = $enrollments->get($row['curp']);
            if ($found && ! isset($used[$found->id])) {
                $used[$found->id] = true;
                $matches[] = ['row' => $row, 'enrollment' => $found, 'reason' => ''];

                continue;
            }

            $pendingNear[] = $row;
        }

        foreach ($pendingNear as $row) {
            $candidates = [];
            foreach ($enrollments as $enrollment) {
                if (isset($used[$enrollment->id])) {
                    continue;
                }
                $curp = strtoupper(trim((string) $enrollment->student->profile->national_id));
                if (abs(strlen($curp) - strlen($row['curp'])) > 1 || levenshtein($curp, $row['curp']) !== 1) {
                    continue;
                }
                if ($this->normalizeName($this->excelStudentName($row)) !== $this->normalizeName($this->profileName($enrollment->student->profile))) {
                    continue;
                }
                $candidates[] = $enrollment;
            }

            if (count($candidates) === 1) {
                $used[$candidates[0]->id] = true;
                $matches[] = ['row' => $row, 'enrollment' => $candidates[0], 'reason' => ''];

                continue;
            }

            $matches[] = [
                'row' => $row,
                'enrollment' => null,
                'reason' => count($candidates) > 1
                    ? 'La CURP parecida coincide con más de un alumno de 2026-2027.'
                    : 'No está inscrito en 2026-2027.',
            ];
        }

        return $matches;
    }

    /**
     * @param  array<string, string>  $row
     * @return array{
     *   phones: list<array<string, string>>,
     *   emails: list<array<string, string>>,
     *   tutors: list<array<string, string>>,
     *   addresses: list<array<string, string>>,
     *   writes: list<callable>
     * }
     */
    private function planChanges(Student $student, array $row): array
    {
        $student->loadMissing('profile.address', 'guardians.profile');
        $profile = $student->profile;
        $phones = [];
        $emails = [];
        $tutors = [];
        $addresses = [];
        $writes = [];

        $phone = $this->digits($row['phone']);
        $email = $this->validEmail($row['email']);
        $studentName = $this->profileName($profile);

        if ($phone !== null && $this->digits((string) $profile->phone_number) !== $phone) {
            $phones[] = $this->contactChange($studentName, 'alumno', (string) $profile->phone_number, $phone);
            $writes[] = function () use ($profile, $phone) {
                $profile->phone_number = $phone;
                $profile->save();
            };
        }

        $matched = $this->matchingGuardian($student, $row);
        if ($matched) {
            $guardianProfile = $matched->profile;
            if ($phone !== null && $this->digits((string) $guardianProfile->phone_number) !== $phone) {
                $phones[] = $this->contactChange($studentName, 'tutor '.$this->profileName($guardianProfile), (string) $guardianProfile->phone_number, $phone);
                $writes[] = function () use ($guardianProfile, $phone) {
                    $guardianProfile->phone_number = $phone;
                    $guardianProfile->save();
                };
            }
            if ($email !== null && strcasecmp((string) $guardianProfile->email, $email) !== 0) {
                $emails[] = $this->contactChange($studentName, 'tutor '.$this->profileName($guardianProfile), (string) $guardianProfile->email, $email);
                $writes[] = function () use ($guardianProfile, $email) {
                    $guardianProfile->email = $email;
                    $guardianProfile->save();
                };
            }
            if ($this->isBlank((string) $matched->Kinship) && $row['kinship'] !== '') {
                $writes[] = function () use ($matched, $row, $student) {
                    $matched->update(['Kinship' => $row['kinship']]);
                    $student->guardians()->updateExistingPivot($matched->id, [
                        'relationship' => $row['kinship'],
                    ]);
                };
            }
        } elseif ($this->hasTutorIdentity($row) || $phone !== null || $email !== null) {
            $tutorName = $this->excelTutorName($row);
            $tutors[] = [
                'student' => $studentName,
                'tutor' => $tutorName !== '' ? $tutorName : 'Tutor',
                'curp' => $this->usableTutorCurp($row['g_curp'], (string) $profile->national_id) ?: 'sin CURP válida',
                'kinship' => $row['kinship'] !== '' ? $row['kinship'] : 'Tutor',
                'phone' => $phone ?? '',
                'email' => $email ?? '',
            ];
            $writes[] = function () use ($student, $row, $phone, $email) {
                $this->addTutor($student, $row, $phone, $email);
            };
            if ($phone !== null) {
                $phones[] = $this->contactChange($studentName, 'tutor nuevo '.($tutorName !== '' ? $tutorName : 'Tutor'), '', $phone);
            }
            if ($email !== null) {
                $emails[] = $this->contactChange($studentName, 'tutor nuevo '.($tutorName !== '' ? $tutorName : 'Tutor'), '', $email);
            }
        }

        $addressChanges = $this->planAddress($profile, $row, $studentName);
        foreach ($addressChanges['fills'] as $fill) {
            $addresses[] = $fill;
        }
        foreach ($addressChanges['writes'] as $write) {
            $writes[] = $write;
        }

        return [
            'phones' => $phones,
            'emails' => $emails,
            'tutors' => $tutors,
            'addresses' => $addresses,
            'writes' => $writes,
        ];
    }

    private function matchingGuardian(Student $student, array $row): ?Guardian
    {
        $excelCurp = $this->usableTutorCurp($row['g_curp'], strtoupper(trim((string) $student->profile?->national_id)));

        foreach ($student->guardians as $guardian) {
            $profile = $guardian->profile;
            if (! $profile) {
                continue;
            }
            $dbCurp = strtoupper(trim((string) $profile->national_id));
            $dbValid = $this->validCurp($dbCurp) && ! $this->isSentinelCurp($dbCurp);
            if ($excelCurp !== null && $dbValid && $excelCurp === $dbCurp) {
                return $guardian;
            }
            if ($this->namesMatch($this->excelTutorName($row), $this->profileName($profile))) {
                if ($excelCurp === null || ! $dbValid || $excelCurp === $dbCurp) {
                    return $guardian;
                }
            }
        }

        return null;
    }

    private function addTutor(Student $student, array $row, ?string $phone, ?string $email): void
    {
        $studentCurp = strtoupper(trim((string) $student->profile?->national_id));
        $guardianCurp = $this->usableTutorCurp($row['g_curp'], $studentCurp);
        if ($guardianCurp === null) {
            $guardianCurp = 'TUT'.substr(preg_replace('/[^A-Z0-9]/', '', $studentCurp.$student->id) ?? (string) $student->id, 0, 15);
            while (Profile::query()->where('national_id', $guardianCurp)->exists()) {
                $guardianCurp = substr($guardianCurp, 0, 16).random_int(0, 9);
            }
        }

        $first = $row['g_name'] !== '' ? $row['g_name'] : 'Tutor';
        $last = trim($row['g_paterno'].' '.$row['g_materno']) ?: 'Sin apellido';
        $kinship = $row['kinship'] !== '' ? $row['kinship'] : 'Tutor';

        $guardianProfile = Profile::query()->firstOrCreate(
            ['national_id' => $guardianCurp],
            [
                'first_name' => $first,
                'last_name' => $last,
                'gender' => 'O',
                'phone_number' => $phone,
                'email' => $email,
            ]
        );
        if ($phone !== null && $this->digits((string) $guardianProfile->phone_number) !== $phone) {
            $guardianProfile->phone_number = $phone;
        }
        if ($email !== null && strcasecmp((string) $guardianProfile->email, $email) !== 0) {
            $guardianProfile->email = $email;
        }
        $guardianProfile->save();

        $guardian = Guardian::query()->firstOrCreate(
            ['profile_id' => $guardianProfile->id],
            ['Kinship' => $kinship]
        );
        if ($this->isBlank((string) $guardian->Kinship) && $kinship !== '') {
            $guardian->update(['Kinship' => $kinship]);
        }

        $student->guardians()->syncWithoutDetaching([
            $guardian->id => ['relationship' => $kinship],
        ]);
    }

    /**
     * @param  array<string, string>  $row
     * @return array{fills: list<array<string, string>>, writes: list<callable>}
     */
    private function planAddress(Profile $profile, array $row, string $studentName): array
    {
        $fills = [];
        $writes = [];
        $incoming = [
            'street_type' => $row['street_type'],
            'street_name' => $row['street'],
            'house_number' => $row['exterior'] !== '' ? $row['exterior'] : $row['interior'],
            'unit_number' => $row['exterior'] !== '' && $row['interior'] !== '' ? $row['interior'] : null,
            'neighborhood_type' => $row['settlement_type'],
            'neighborhood_name' => $row['settlement'],
        ];
        $hasIncoming = $incoming['street_name'] !== '' || $incoming['neighborhood_name'] !== '' || $incoming['house_number'] !== '';
        if (! $hasIncoming) {
            return ['fills' => $fills, 'writes' => $writes];
        }

        $address = $profile->address;
        if (! $address) {
            $fills[] = [
                'student' => $studentName,
                'field' => 'domicilio',
                'from' => '',
                'to' => trim($incoming['street_type'].' '.$incoming['street_name'].' '.$incoming['house_number']),
            ];
            $writes[] = function () use ($profile, $incoming) {
                $created = Address::query()->create([
                    'street_type' => $incoming['street_type'] !== '' ? $incoming['street_type'] : 'CALLE',
                    'street_name' => $incoming['street_name'] !== '' ? $incoming['street_name'] : 'SIN CALLE',
                    'house_number' => $incoming['house_number'] !== '' ? $incoming['house_number'] : 'S/N',
                    'unit_number' => $incoming['unit_number'],
                    'neighborhood_type' => $incoming['neighborhood_type'] !== '' ? $incoming['neighborhood_type'] : 'COLONIA',
                    'neighborhood_name' => $incoming['neighborhood_name'] !== '' ? $incoming['neighborhood_name'] : 'SIN COLONIA',
                    'postal_code' => '00000',
                    'city' => 'Oaxaca de Juárez',
                    'state' => 'Oaxaca',
                ]);
                $profile->address_id = $created->id;
                $profile->save();
            };

            return ['fills' => $fills, 'writes' => $writes];
        }

        $patch = [];
        foreach (['street_type', 'street_name', 'house_number', 'neighborhood_type', 'neighborhood_name'] as $field) {
            if ($this->isBlank((string) $address->{$field}) && $incoming[$field] !== '') {
                $patch[$field] = $incoming[$field];
                $fills[] = [
                    'student' => $studentName,
                    'field' => $field,
                    'from' => (string) $address->{$field},
                    'to' => $incoming[$field],
                ];
            }
        }
        if ($this->isBlank((string) $address->unit_number) && $incoming['unit_number']) {
            $patch['unit_number'] = $incoming['unit_number'];
        }
        if ($patch !== []) {
            $writes[] = function () use ($address, $patch) {
                $address->update($patch);
            };
        }

        return ['fills' => $fills, 'writes' => $writes];
    }

    /**
     * @return list<array<string, string>>
     */
    private function readRows(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        $sheet = $book->getSheetByName(ApplyFirstGradeRosterService::SHEET) ?: $book->getSheet(0);
        if (! $sheet) {
            throw new RuntimeException('El Excel no tiene una hoja de directorio.');
        }

        $map = $this->columnMap($sheet);
        $rows = [];
        $highest = $sheet->getHighestRow();
        for ($r = 5; $r <= $highest; $r++) {
            $row = [
                'row' => (string) $r,
                'first' => $this->cell($sheet, $map['first'], $r),
                'paterno' => $this->cell($sheet, $map['paterno'], $r),
                'materno' => $this->cell($sheet, $map['materno'], $r),
                'curp' => strtoupper($this->cell($sheet, $map['curp'], $r)),
                'street_type' => $this->cell($sheet, $map['street_type'], $r),
                'street' => $this->cell($sheet, $map['street'], $r),
                'interior' => $this->cell($sheet, $map['interior'], $r),
                'exterior' => $this->cell($sheet, $map['exterior'], $r),
                'settlement_type' => $this->cell($sheet, $map['settlement_type'], $r),
                'settlement' => $this->cell($sheet, $map['settlement'], $r),
                'g_paterno' => $this->cell($sheet, $map['g_paterno'], $r),
                'g_materno' => $this->cell($sheet, $map['g_materno'], $r),
                'g_name' => $this->cell($sheet, $map['g_name'], $r),
                'g_curp' => strtoupper($this->cell($sheet, $map['g_curp'], $r)),
                'phone' => $this->cell($sheet, $map['phone'], $r),
                'email' => $map['email'] !== '' ? $this->cell($sheet, $map['email'], $r) : '',
                'kinship' => $this->cell($sheet, $map['kinship'], $r),
            ];
            if ($row['curp'] === '' && $row['first'] === '' && $row['paterno'] === '') {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function columnMap($sheet): array
    {
        $headerG = mb_strtoupper($this->cell($sheet, 'G', 4));
        $sampleG = strtoupper($this->cell($sheet, 'G', 5));
        $newLayout = str_contains($headerG, 'CURP') || $this->validCurp($sampleG);
        $email = $this->findHeaderColumn($sheet, ['CORREO', 'EMAIL', 'E-MAIL', 'MAIL']);

        if ($newLayout) {
            return [
                'first' => 'C',
                'paterno' => 'D',
                'materno' => 'E',
                'curp' => 'G',
                'street_type' => 'O',
                'street' => 'P',
                'interior' => 'Q',
                'exterior' => 'R',
                'settlement_type' => 'S',
                'settlement' => 'T',
                'g_paterno' => 'U',
                'g_materno' => 'V',
                'g_name' => 'W',
                'g_curp' => 'X',
                'phone' => 'Y',
                'kinship' => 'Z',
                'email' => $email ?? '',
            ];
        }

        return [
            'first' => 'B',
            'paterno' => 'C',
            'materno' => 'D',
            'curp' => 'F',
            'street_type' => 'N',
            'street' => 'O',
            'interior' => 'P',
            'exterior' => 'Q',
            'settlement_type' => 'R',
            'settlement' => 'S',
            'g_paterno' => 'T',
            'g_materno' => 'U',
            'g_name' => 'V',
            'g_curp' => 'W',
            'phone' => 'X',
            'kinship' => 'Y',
            'email' => $email ?? '',
        ];
    }

    private function findHeaderColumn($sheet, array $needles): ?string
    {
        $columns = array_merge(range('A', 'Z'), ['AA', 'AB', 'AC', 'AD', 'AE', 'AF']);
        for ($r = 1; $r <= 6; $r++) {
            foreach ($columns as $column) {
                $header = mb_strtoupper($this->cell($sheet, $column, $r));
                foreach ($needles as $needle) {
                    if ($header !== '' && str_contains($header, $needle)) {
                        return $column;
                    }
                }
            }
        }

        return null;
    }

    private function cell($sheet, string $column, int $row): string
    {
        if ($column === '') {
            return '';
        }

        return trim((string) $sheet->getCell($column.$row)->getFormattedValue());
    }

    /**
     * @param  array{row: array<string, string>, enrollment: ?Enrollment, reason: string}  $match
     * @return array{row: string, curp: string, name: string, reason: string}
     */
    private function issue(array $match, string $reason): array
    {
        return [
            'row' => $match['row']['row'],
            'curp' => $match['row']['curp'],
            'name' => $this->excelStudentName($match['row']),
            'reason' => $reason,
        ];
    }

    /**
     * @return array{student: string, who: string, from: string, to: string}
     */
    private function contactChange(string $student, string $who, string $from, string $to): array
    {
        return [
            'student' => $student,
            'who' => $who,
            'from' => $from !== '' ? $from : '—',
            'to' => $to,
        ];
    }

    private function hasTutorIdentity(array $row): bool
    {
        return $this->excelTutorName($row) !== ''
            || $this->usableTutorCurp($row['g_curp'], '') !== null;
    }

    private function usableTutorCurp(string $curp, string $studentCurp): ?string
    {
        $curp = strtoupper(trim($curp));
        if (! $this->validCurp($curp) || $this->isSentinelCurp($curp)) {
            return null;
        }
        if ($studentCurp !== '' && $curp === strtoupper(trim($studentCurp))) {
            return null;
        }

        return $curp;
    }

    private function isSentinelCurp(string $curp): bool
    {
        return str_starts_with(strtoupper($curp), 'TUT');
    }

    private function isBlank(string $value): bool
    {
        $folded = $this->normalizeName($value);

        return $folded === '' || in_array($folded, ['SINCALLE', 'SINCOLONIA', 'SN', '00000', 'SINAPELLIDO', 'TUTOR'], true);
    }

    private function namesMatch(string $left, string $right): bool
    {
        $leftTokens = $this->nameTokens($left);
        $rightTokens = $this->nameTokens($right);
        if ($leftTokens === [] || $rightTokens === []) {
            return false;
        }
        if ($leftTokens === $rightTokens) {
            return true;
        }
        $shorter = count($leftTokens) <= count($rightTokens) ? $leftTokens : $rightTokens;
        $longer = count($leftTokens) <= count($rightTokens) ? $rightTokens : $leftTokens;
        $missing = array_diff($shorter, $longer);

        return $missing === [] && count($shorter) >= 2;
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
            fn (string $token) => strlen($token) >= 3 && ! in_array($token, ['DEL', 'LAS', 'LOS', 'DE'], true)
        ));
    }

    private function digits(string $value): ?string
    {
        $digits = preg_replace('/\D/', '', $value) ?? '';

        return strlen($digits) === 10 ? $digits : null;
    }

    private function validEmail(string $value): ?string
    {
        $value = strtolower(trim($value));
        if ($value === '' || ! filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        return $value;
    }

    private function validCurp(string $curp): bool
    {
        return preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/', $curp) === 1;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function excelStudentName(array $row): string
    {
        return trim($row['first'].' '.$row['paterno'].' '.$row['materno']);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function excelTutorName(array $row): string
    {
        return trim($row['g_name'].' '.$row['g_paterno'].' '.$row['g_materno']);
    }

    private function profileName(Profile $profile): string
    {
        return trim($profile->first_name.' '.$profile->last_name);
    }

    private function normalizeName(string $value): string
    {
        $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtoupper($value, 'UTF-8')) ?: $value;

        return preg_replace('/[^A-Z0-9]/', '', $folded) ?? '';
    }
}
