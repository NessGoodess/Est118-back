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
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use RuntimeException;
use Throwable;

class SyncDirectoryDetailsService
{
    public const NOT_SPECIFIED = 'NO_ESPECIFICADO';

    private const ADDRESS_FIELDS = [
        'street_type' => 'vialidad',
        'street_name' => 'calle',
        'house_number' => 'número',
        'unit_number' => 'interior',
        'neighborhood_type' => 'asentamiento',
        'neighborhood_name' => 'colonia',
        'city' => 'municipio',
    ];

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

        [$sheetName, $rows] = $this->readRows($path);
        $enrollments = $this->periodEnrollments($year->id, $grade->id);
        $matches = $this->matchRows($rows, $enrollments);

        $curpChanges = [];
        $phoneChanges = [];
        $emailChanges = [];
        $tutorsAdded = [];
        $addressChanges = [];
        $skipped = [];
        $errors = [];
        $applied = 0;

        foreach ($matches as $match) {
            if ($match['enrollment'] === null) {
                $skipped[] = $this->issue($match, $match['reason']);

                continue;
            }

            try {
                $changes = $this->planChanges($match['enrollment']->student, $match['row'], $match['method']);
                array_push($curpChanges, ...$changes['curps']);
                array_push($phoneChanges, ...$changes['phones']);
                array_push($emailChanges, ...$changes['emails']);
                array_push($tutorsAdded, ...$changes['tutors']);
                array_push($addressChanges, ...$changes['addresses']);
                foreach ($changes['problems'] as $problem) {
                    $skipped[] = $this->issue($match, $problem);
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

        $matchedIds = [];
        foreach ($matches as $match) {
            if ($match['enrollment']) {
                $matchedIds[$match['enrollment']->id] = true;
            }
        }
        $notOnList = $enrollments
            ->reject(fn (Enrollment $enrollment) => isset($matchedIds[$enrollment->id]))
            ->map(fn (Enrollment $enrollment) => [
                'curp' => strtoupper(trim((string) $enrollment->student->profile->national_id)),
                'name' => $this->profileName($enrollment->student->profile),
                'group' => (string) ($enrollment->classGroup?->name ?? ''),
            ])
            ->sortBy(fn (array $row) => $row['group'].' '.$row['name'])
            ->values()
            ->all();

        return [
            'dry_run' => $dryRun,
            'academic_year_id' => $year->id,
            'academic_year_label' => trim($year->year_start.'-'.$year->year_end),
            'grade' => $gradeName,
            'sheet' => $sheetName,
            'rows' => count($rows),
            'matched' => count($matchedIds),
            'applied' => $dryRun ? 0 : $applied,
            'curp_changes' => $curpChanges,
            'phone_changes' => $phoneChanges,
            'email_changes' => $emailChanges,
            'tutors_added' => $tutorsAdded,
            'address_changes' => $addressChanges,
            'not_on_list' => $notOnList,
            'skipped' => $skipped,
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
            ->with(['student.profile.address', 'student.guardians.profile', 'classGroup'])
            ->get()
            ->filter(fn (Enrollment $enrollment) => $enrollment->student?->profile)
            ->values();
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  Collection<int, Enrollment>  $enrollments
     * @return list<array{row: array<string, string>, enrollment: ?Enrollment, method: string, reason: string}>
     */
    private function matchRows(array $rows, Collection $enrollments): array
    {
        $byCurp = [];
        foreach ($enrollments as $enrollment) {
            $byCurp[strtoupper(trim((string) $enrollment->student->profile->national_id))][] = $enrollment;
        }

        $used = [];
        $results = [];
        $pending = [];

        foreach ($rows as $index => $row) {
            $found = array_values(array_filter(
                $byCurp[$row['curp']] ?? [],
                fn (Enrollment $enrollment) => ! isset($used[$enrollment->id])
            ));
            if ($row['curp'] !== '' && count($found) === 1) {
                $used[$found[0]->id] = true;
                $results[$index] = ['row' => $row, 'enrollment' => $found[0], 'method' => 'exact', 'reason' => ''];

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
                    if (abs(strlen($curp) - strlen($row['curp'])) > 2 || levenshtein($curp, $row['curp']) > 2) {
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
                $results[$index] = ['row' => $row, 'enrollment' => $candidates[0], 'method' => 'near', 'reason' => ''];
                unset($pending[$index]);
            }
        }

        foreach ($pending as $index => $row) {
            $candidates = [];
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
                $results[$index] = ['row' => $row, 'enrollment' => $candidates[0], 'method' => 'name', 'reason' => ''];

                continue;
            }

            $results[$index] = [
                'row' => $row,
                'enrollment' => null,
                'method' => '',
                'reason' => count($candidates) > 1
                    ? 'El nombre coincide con más de un alumno de 2026-2027.'
                    : 'No se encontró en 2026-2027 ni por CURP ni por nombre.',
            ];
        }

        ksort($results);

        return array_values($results);
    }

    /**
     * @param  array<string, string>  $row
     * @return array{
     *   curps: list<array<string, string>>,
     *   phones: list<array<string, string>>,
     *   emails: list<array<string, string>>,
     *   tutors: list<array<string, string>>,
     *   addresses: list<array<string, string>>,
     *   problems: list<string>,
     *   writes: list<callable>
     * }
     */
    private function planChanges(Student $student, array $row, string $method): array
    {
        $student->loadMissing('profile.address', 'guardians.profile');
        $profile = $student->profile;
        $studentName = $this->profileName($profile);
        $plan = [
            'curps' => [],
            'phones' => [],
            'emails' => [],
            'tutors' => [],
            'addresses' => [],
            'problems' => [],
            'writes' => [],
        ];

        $this->planCurp($profile, $row, $method, $studentName, $plan);

        $phone = $this->digits($row['phone']);
        $email = $this->validEmail($row['email']);
        if ($row['phone'] !== '' && $phone === null) {
            $plan['problems'][] = 'Teléfono del Excel inválido ('.$row['phone'].'); no se cambió.';
        }

        $matched = $this->matchingGuardian($student, $row);
        if ($matched) {
            $guardianProfile = $matched->profile;
            $who = 'tutor '.$this->profileName($guardianProfile);
            if ($phone !== null && $this->digits((string) $guardianProfile->phone_number) !== $phone) {
                $plan['phones'][] = $this->change($studentName, $who, (string) $guardianProfile->phone_number, $phone);
                $plan['writes'][] = function () use ($guardianProfile, $phone) {
                    $guardianProfile->phone_number = $phone;
                    $guardianProfile->save();
                };
            }
            if ($email !== null && strcasecmp((string) $guardianProfile->email, $email) !== 0) {
                $plan['emails'][] = $this->change($studentName, $who, (string) $guardianProfile->email, $email);
                $plan['writes'][] = function () use ($guardianProfile, $email) {
                    $guardianProfile->email = $email;
                    $guardianProfile->save();
                };
            }
            if ($row['kinship'] !== '' && $this->isBlank((string) $matched->Kinship)) {
                $plan['writes'][] = function () use ($matched, $row, $student) {
                    $matched->update(['Kinship' => $row['kinship']]);
                    $student->guardians()->updateExistingPivot($matched->id, [
                        'relationship' => $row['kinship'],
                    ]);
                };
            }
        } elseif ($this->excelTutorName($row) !== '' || $this->usableTutorCurp($row['g_curp'], '') !== null) {
            $tutorName = $this->excelTutorName($row) !== '' ? $this->excelTutorName($row) : self::NOT_SPECIFIED;
            $plan['tutors'][] = [
                'student' => $studentName,
                'tutor' => $tutorName,
                'curp' => $this->usableTutorCurp($row['g_curp'], (string) $profile->national_id) ?? 'sin CURP en el Excel',
                'kinship' => $row['kinship'] !== '' ? $row['kinship'] : 'sin parentesco',
                'phone' => $phone ?? '',
                'email' => $email ?? '',
            ];
            if ($phone !== null) {
                $plan['phones'][] = $this->change($studentName, 'tutor nuevo '.$tutorName, '', $phone);
            }
            if ($email !== null) {
                $plan['emails'][] = $this->change($studentName, 'tutor nuevo '.$tutorName, '', $email);
            }
            $plan['writes'][] = function () use ($student, $row, $phone, $email) {
                $this->addTutor($student, $row, $phone, $email);
            };
        } elseif ($phone !== null) {
            $first = $student->guardians->first();
            if ($first?->profile && $this->digits((string) $first->profile->phone_number) !== $phone) {
                $guardianProfile = $first->profile;
                $plan['phones'][] = $this->change($studentName, 'tutor '.$this->profileName($guardianProfile), (string) $guardianProfile->phone_number, $phone);
                $plan['writes'][] = function () use ($guardianProfile, $phone) {
                    $guardianProfile->phone_number = $phone;
                    $guardianProfile->save();
                };
            }
        }

        $this->planAddress($profile, $row, $studentName, $plan);

        return $plan;
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, list<mixed>>  $plan
     */
    private function planCurp(Profile $profile, array $row, string $method, string $studentName, array &$plan): void
    {
        $dbCurp = strtoupper(trim((string) $profile->national_id));
        $excelCurp = $row['curp'];
        if ($method === 'exact' || $excelCurp === '' || $excelCurp === $dbCurp) {
            return;
        }

        $how = $method === 'near' ? 'CURP parecida y mismo nombre' : 'mismo nombre';
        if (! $this->validCurp($excelCurp)) {
            $plan['problems'][] = 'Coincide por '.$how.', pero la CURP del Excel ('.$excelCurp.') no es válida; se conserva '.$dbCurp.'.';

            return;
        }

        $birth = $this->dateFromCurp($excelCurp);
        $age = $birth !== null ? \Illuminate\Support\Carbon::parse($birth)->age : null;
        if ($age === null || $age < 9 || $age > 20) {
            $plan['problems'][] = 'Coincide por '.$how.', pero la CURP del Excel ('.$excelCurp.') da una fecha de nacimiento imposible'
                .($birth !== null ? ' ('.$birth.')' : '').'; se conserva '.$dbCurp.'.';

            return;
        }

        $owner = Profile::query()
            ->where('national_id', $excelCurp)
            ->whereKeyNot($profile->id)
            ->first();
        if ($owner) {
            $plan['problems'][] = 'La CURP del Excel '.$excelCurp.' ya pertenece a '.$this->profileName($owner).' (posible duplicado); se conserva '.$dbCurp.'.';

            return;
        }

        $gender = $this->genderFromCurp($excelCurp);
        $plan['curps'][] = [
            'student' => $studentName,
            'from' => $dbCurp !== '' ? $dbCurp : '—',
            'to' => $excelCurp,
            'how' => $how,
        ];
        $plan['writes'][] = function () use ($profile, $excelCurp, $birth, $gender) {
            $profile->national_id = $excelCurp;
            if ($birth !== null) {
                $profile->birth_date = $birth;
            }
            if ($gender !== null) {
                $profile->gender = $gender;
            }
            $profile->save();
        };
    }

    /**
     * @param  array<string, string>  $row
     * @param  array<string, list<mixed>>  $plan
     */
    private function planAddress(Profile $profile, array $row, string $studentName, array &$plan): void
    {
        $incoming = [
            'street_type' => $row['street_type'],
            'street_name' => $row['street'],
            'house_number' => $row['exterior'] !== '' ? $row['exterior'] : $row['interior'],
            'unit_number' => $row['exterior'] !== '' && $row['interior'] !== '' ? $row['interior'] : '',
            'neighborhood_type' => $row['settlement_type'],
            'neighborhood_name' => $row['settlement'],
            'city' => $row['city'],
        ];
        if ($incoming['street_name'] === '' && $incoming['neighborhood_name'] === '') {
            return;
        }

        $address = $profile->address;
        $patch = [];
        foreach (array_keys(self::ADDRESS_FIELDS) as $field) {
            if ($field === 'unit_number') {
                if ($incoming['house_number'] !== '' && (string) ($address?->unit_number ?? '') !== $incoming['unit_number']) {
                    $patch['unit_number'] = $incoming['unit_number'] !== '' ? $incoming['unit_number'] : null;
                }

                continue;
            }
            if ($incoming[$field] === '') {
                continue;
            }
            if ($this->normalizeName((string) ($address?->{$field} ?? '')) !== $this->normalizeName($incoming[$field])) {
                $patch[$field] = $incoming[$field];
            }
        }
        if ($patch === []) {
            return;
        }

        $plan['addresses'][] = [
            'student' => $studentName,
            'from' => $this->addressLabel($address?->only(array_keys(self::ADDRESS_FIELDS)) ?? []),
            'to' => $this->addressLabel(array_merge($address?->only(array_keys(self::ADDRESS_FIELDS)) ?? [], $patch)),
        ];
        $plan['writes'][] = function () use ($profile, $address, $patch) {
            if ($address) {
                $address->update($patch);

                return;
            }
            $created = Address::query()->create(array_merge([
                'street_type' => self::NOT_SPECIFIED,
                'street_name' => self::NOT_SPECIFIED,
                'house_number' => self::NOT_SPECIFIED,
                'neighborhood_type' => self::NOT_SPECIFIED,
                'neighborhood_name' => self::NOT_SPECIFIED,
                'postal_code' => '00000',
                'city' => self::NOT_SPECIFIED,
                'state' => self::NOT_SPECIFIED,
            ], $patch));
            $profile->address_id = $created->id;
            $profile->save();
        };
    }

    /**
     * @param  array<string, mixed>  $parts
     */
    private function addressLabel(array $parts): string
    {
        $line = trim(implode(' ', array_filter([
            $parts['street_type'] ?? '',
            $parts['street_name'] ?? '',
            $parts['house_number'] ?? '',
            ($parts['unit_number'] ?? '') !== '' && ($parts['unit_number'] ?? null) !== null ? 'int. '.$parts['unit_number'] : '',
        ])));
        $area = trim(implode(' ', array_filter([
            $parts['neighborhood_type'] ?? '',
            $parts['neighborhood_name'] ?? '',
        ])));
        $label = trim(implode(', ', array_filter([$line, $area, (string) ($parts['city'] ?? '')])));

        return $label !== '' ? $label : '—';
    }

    private function matchingGuardian(Student $student, array $row): ?Guardian
    {
        $excelCurp = $this->usableTutorCurp($row['g_curp'], strtoupper(trim((string) $student->profile?->national_id)));
        $excelName = $this->excelTutorName($row);

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
            if ($excelName !== '' && $this->namesMatch($excelName, $this->profileName($profile))) {
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
            $guardianCurp = self::unspecifiedCurp();
        }

        $kinship = $row['kinship'] !== '' ? $row['kinship'] : null;
        $guardianProfile = Profile::query()->firstOrCreate(
            ['national_id' => $guardianCurp],
            [
                'first_name' => $row['g_name'] !== '' ? $row['g_name'] : self::NOT_SPECIFIED,
                'last_name' => trim($row['g_paterno'].' '.$row['g_materno']) ?: self::NOT_SPECIFIED,
                'gender' => 'O',
                'phone_number' => $phone,
                'email' => $email,
            ]
        );
        if ($phone !== null) {
            $guardianProfile->phone_number = $phone;
        }
        if ($email !== null) {
            $guardianProfile->email = $email;
        }
        $guardianProfile->save();

        $guardian = Guardian::query()->firstOrCreate(
            ['profile_id' => $guardianProfile->id],
            ['Kinship' => $kinship]
        );
        $student->guardians()->syncWithoutDetaching([
            $guardian->id => ['relationship' => $kinship],
        ]);
    }

    /**
     * @return array{0: string, 1: list<array<string, string>>}
     */
    public function readRows(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);

        foreach ($book->getWorksheetIterator() as $sheet) {
            $layout = $this->detectLayout($sheet);
            if ($layout === null) {
                continue;
            }

            $rows = [];
            $highest = $sheet->getHighestRow();
            for ($r = $layout['header_row'] + 1; $r <= $highest; $r++) {
                $value = fn (string $key) => isset($layout['columns'][$key])
                    ? $this->cell($sheet, $layout['columns'][$key], $r)
                    : '';

                $first = $value('first');
                $paterno = $value('paterno');
                $materno = $value('materno');
                $full = $value('full');
                $studentName = $full !== '' ? $full : trim($first.' '.$paterno.' '.$materno);
                $curp = strtoupper(preg_replace('/\s+/', '', $value('curp')) ?? '');
                if ($curp === '' && $studentName === '') {
                    continue;
                }

                $rows[] = [
                    'row' => (string) $r,
                    'student_name' => $studentName,
                    'first' => $first,
                    'paterno' => $paterno,
                    'materno' => $materno,
                    'full' => $full,
                    'curp' => $curp,
                    'group' => $value('group'),
                    'tech' => $value('tech'),
                    'street_type' => $value('street_type'),
                    'street' => $value('street'),
                    'interior' => $value('interior'),
                    'exterior' => $value('exterior'),
                    'settlement_type' => $value('settlement_type'),
                    'settlement' => $value('settlement'),
                    'city' => $value('city'),
                    'g_paterno' => $value('g_paterno'),
                    'g_materno' => $value('g_materno'),
                    'g_name' => $value('g_name'),
                    'g_curp' => strtoupper(preg_replace('/\s+/', '', $value('g_curp')) ?? ''),
                    'phone' => $value('phone'),
                    'email' => $value('email'),
                    'kinship' => $value('kinship'),
                ];
            }

            return [$sheet->getTitle(), $rows];
        }

        throw new RuntimeException('El Excel no tiene una hoja con CURP del alumno y número de contacto.');
    }

    /**
     * @return array{header_row: int, columns: array<string, string>}|null
     */
    private function detectLayout(Worksheet $sheet): ?array
    {
        $maxColumn = min(Coordinate::columnIndexFromString($sheet->getHighestColumn()), 60);

        for ($headerRow = 1; $headerRow <= 10; $headerRow++) {
            $headers = [];
            for ($c = 1; $c <= $maxColumn; $c++) {
                $column = Coordinate::stringFromColumnIndex($c);
                $headers[$c] = $this->headerKey($this->cell($sheet, $column, $headerRow));
            }
            $studentCurp = array_search(true, array_map(fn (string $h) => str_contains($h, 'CURP'), $headers), true);
            if ($studentCurp === false) {
                continue;
            }

            $sections = [];
            for ($c = 1; $c <= $maxColumn; $c++) {
                $sections[$c] = $headerRow > 1
                    ? $this->headerKey($this->cell($sheet, Coordinate::stringFromColumnIndex($c), $headerRow - 1))
                    : '';
            }

            $tutorStart = null;
            foreach ($sections as $c => $text) {
                if ($c > $studentCurp && (str_contains($text, 'TUTOR') || str_contains($text, 'PADRE'))) {
                    $tutorStart = $c;
                    break;
                }
            }

            $columns = [];
            for ($c = 1; $c <= $maxColumn; $c++) {
                $column = Coordinate::stringFromColumnIndex($c);
                $header = $headers[$c];
                $section = $sections[$c];
                $isTutor = $tutorStart !== null && $c >= $tutorStart;

                if (str_contains($header, 'CONTACTO') || str_contains($section, 'CONTACTO')) {
                    $columns['phone'] ??= $column;

                    continue;
                }
                if (str_contains($header, 'PARENTESCO') || str_contains($section, 'PARENTESCO')) {
                    $columns['kinship'] ??= $column;

                    continue;
                }
                if (str_contains($header, 'CORREO') || str_contains($header, 'EMAIL') || str_contains($section, 'CORREO')) {
                    $columns['email'] ??= $column;

                    continue;
                }
                if ($header === '') {
                    continue;
                }

                if ($isTutor) {
                    $key = match (true) {
                        str_contains($header, 'PATERNO') => 'g_paterno',
                        str_contains($header, 'MATERNO') => 'g_materno',
                        str_contains($header, 'CURP') => 'g_curp',
                        str_starts_with($header, 'NOMBRE') => 'g_name',
                        default => null,
                    };
                } else {
                    $key = match (true) {
                        str_contains($header, 'CURP') => 'curp',
                        str_contains($header, 'NOMBRE COMPLETO') => 'full',
                        str_contains($header, 'PATERNO') => 'paterno',
                        str_contains($header, 'MATERNO') => 'materno',
                        str_contains($header, 'NOMBRE DE LA VIALIDAD') => 'street',
                        $header === 'VIALIDAD' => 'street_type',
                        str_contains($header, 'INTERIOR') => 'interior',
                        str_contains($header, 'EXTERIOR') => 'exterior',
                        str_contains($header, 'NOMBRE DEL ASENTAMIENTO') => 'settlement',
                        $header === 'ASENTAMIENTO' => 'settlement_type',
                        str_contains($header, 'MUNICIPIO') => 'city',
                        $header === 'GRUPO' => 'group',
                        str_contains($header, 'TECNOLOGIA') || str_contains($header, 'TALLER') => 'tech',
                        $header === 'NOMBRE' || $header === 'NOMBRE S' || $header === 'NOMBRES' => 'first',
                        default => null,
                    };
                }
                if ($key !== null) {
                    $columns[$key] ??= $column;
                }
            }

            if (! isset($columns['curp']) || ! isset($columns['phone'])) {
                continue;
            }

            return ['header_row' => $headerRow, 'columns' => $columns];
        }

        return null;
    }

    private function headerKey(string $value): string
    {
        $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtoupper($value, 'UTF-8')) ?: mb_strtoupper($value, 'UTF-8');
        $folded = preg_replace('/[^A-Z0-9]+/', ' ', $folded) ?? $folded;

        return trim(preg_replace('/\s+/', ' ', $folded) ?? $folded);
    }

    private function cell(Worksheet $sheet, string $column, int $row): string
    {
        return trim((string) $sheet->getCell($column.$row)->getFormattedValue());
    }

    /**
     * @param  array{row: array<string, string>, enrollment: ?Enrollment, method: string, reason: string}  $match
     * @return array{row: string, curp: string, name: string, reason: string}
     */
    private function issue(array $match, string $reason): array
    {
        return [
            'row' => $match['row']['row'],
            'curp' => $match['row']['curp'],
            'name' => $match['row']['student_name'],
            'reason' => $reason,
        ];
    }

    /**
     * @return array{student: string, who: string, from: string, to: string}
     */
    private function change(string $student, string $who, string $from, string $to): array
    {
        return [
            'student' => $student,
            'who' => $who,
            'from' => $from !== '' ? $from : '—',
            'to' => $to,
        ];
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
        $curp = strtoupper($curp);

        return str_starts_with($curp, 'TUT') || str_starts_with($curp, self::NOT_SPECIFIED);
    }

    /**
     * La CURP del perfil es obligatoria y única, así que el tutor sin CURP lleva un consecutivo.
     */
    public static function unspecifiedCurp(): string
    {
        $taken = Profile::query()
            ->where('national_id', 'like', self::NOT_SPECIFIED.'%')
            ->pluck('national_id')
            ->map(fn (string $value) => preg_match('/^'.self::NOT_SPECIFIED.'_(\d+)$/', $value, $m) === 1 ? (int) $m[1] : 0)
            ->max() ?? 0;

        return self::NOT_SPECIFIED.'_'.($taken + 1);
    }

    private function isBlank(string $value): bool
    {
        $folded = $this->normalizeName($value);

        return $folded === '' || in_array($folded, ['SINCALLE', 'SINCOLONIA', 'SN', '00000', 'SINAPELLIDO', 'TUTOR', 'NOESPECIFICADO'], true);
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
