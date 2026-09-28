<?php

namespace App\Console\Commands\temporal;

use App\Enums\AdmissionWorkshop;
use App\Enums\EnrollmentStatus;
use App\Enums\PreEnrollmentStatus;
use App\Enums\PromotionResult;
use App\Enums\ReEnrollmentPeriodStatus;
use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use App\Exceptions\AdmissionConversionException;
use App\Models\AcademicYear;
use App\Models\Address;
use App\Models\AdmissionIntakeSetting;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Guardian;
use App\Models\PreEnrollment;
use App\Models\Profile;
use App\Models\School\ReEnrollmentPeriod;
use App\Models\Student;
use App\Models\Workshop;
use App\Services\ConvertPreEnrollmentToStudentService;
use App\Services\PreEnrollmentProcessService;
use App\Services\WorkshopEnrollmentWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use RuntimeException;
use Throwable;

class ApplyFirstGradeRosterService
{
    public const SHEET = 'DIRECTORIO PRIMERO 26-27';

    public function __construct(
        private readonly ConvertPreEnrollmentToStudentService $converter,
        private readonly WorkshopEnrollmentWriter $workshopWriter,
        private readonly PreEnrollmentProcessService $process,
    ) {}

    public static function resolveYearId(int $yearId = 0): int
    {
        if ($yearId > 0) {
            return $yearId;
        }

        $period = ReEnrollmentPeriod::query()
            ->where('status', ReEnrollmentPeriodStatus::OPEN)
            ->orderByDesc('id')
            ->first();
        if ($period) {
            return (int) $period->to_academic_year_id;
        }

        return (int) AcademicYear::query()->where('is_active', true)->value('id');
    }

    public static function resolveDefaultFile(): string
    {
        $directories = [
            storage_path('app/temp/listasExcel'),
            dirname(base_path()).DIRECTORY_SEPARATOR.'listasExcel',
        ];

        foreach ($directories as $directory) {
            if (! is_dir($directory)) {
                continue;
            }
            $matches = glob($directory.DIRECTORY_SEPARATOR.'*.xlsx') ?: [];
            foreach ($matches as $file) {
                $name = mb_strtoupper(basename($file));
                if (str_contains($name, 'PRIMEROS') || str_contains($name, 'PRIMERO')) {
                    return $file;
                }
            }
        }

        return '';
    }

    /**
     * @return array<string, mixed>
     */
    public function apply(string $path, int $academicYearId, bool $dryRun = true): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('No se encontró el Excel de listas.');
        }

        $settings = AdmissionIntakeSetting::current();
        $this->assertGates($settings);

        $year = AcademicYear::query()->find($academicYearId);
        if (! $year) {
            throw new RuntimeException('No existe el ciclo escolar indicado.');
        }

        $firstGrade = GradeLevel::query()->where('name', '1°')->first();
        if (! $firstGrade) {
            throw new RuntimeException('No existe el grado 1° en el catálogo.');
        }

        $groups = ClassGroup::query()
            ->where('academic_year_id', $year->id)
            ->where('grade_level_id', $firstGrade->id)
            ->get()
            ->keyBy(fn (ClassGroup $group) => strtoupper(trim($group->name)));

        $workshops = Workshop::query()->where('is_active', true)->get();
        $rows = $this->readRows($path);
        $pres = PreEnrollment::query()->get();
        $students = Student::query()->with('profile')->get();

        $matches = $this->matchRows($rows, $pres);
        $this->attachStudents($matches, $students);

        $destByStudent = $this->firstGradeEnrollments($year->id, $firstGrade->id);
        $originYearId = $this->originYearId($year->id);
        $originByStudent = $originYearId
            ? $this->firstGradeEnrollments($originYearId, $firstGrade->id)
            : collect();

        $planned = [];
        $skipped = [];
        $applied = 0;
        $errors = [];
        $converted = [];
        $createdLate = [];
        $placedExisting = [];
        $alreadyInFirst = [];
        $listStudentIds = [];

        foreach ($matches as $match) {
            $row = $match['row'];
            $pre = $match['pre'];
            $student = $match['student'];
            $letter = $this->groupLetter($row['group']);
            $group = $letter ? $groups->get($letter) : null;
            $workshop = $this->resolveWorkshop($row['tech'], $workshops);

            if (! $group) {
                $skipped[] = $this->skip($match, 'El grupo '.$row['group'].' no existe en 1° de este ciclo.');

                continue;
            }
            if (! $workshop) {
                $skipped[] = $this->skip($match, 'El taller '.$row['tech'].' no está en el catálogo.');

                continue;
            }
            if ($pre && $pre->status === PreEnrollmentStatus::REJECTED) {
                $skipped[] = $this->skip($match, 'La preinscripción está rechazada.');

                continue;
            }
            if (! $pre && ! $student && ! $this->validCurp($row['curp'])) {
                $skipped[] = $this->skip($match, 'La CURP del Excel no es válida para un alta tardía.');

                continue;
            }

            $dest = $student ? $destByStudent->get($student->id) : null;
            $action = $dest
                ? 'already_enrolled'
                : ($pre ? 'convert' : ($student ? 'place_existing' : 'create_late'));

            $planned[] = [
                'row' => $row['row'],
                'pre_enrollment_id' => $pre?->id,
                'folio' => $pre?->folio,
                'curp' => $pre?->curp ?: ($student?->profile?->national_id ?: $row['curp']),
                'excel_curp' => $row['curp'],
                'near_curp' => $match['near'] || $match['near_student'],
                'name' => $this->excelName($row),
                'group' => $group->name,
                'workshop' => $workshop->name,
                'action' => $action,
                'warnings' => $pre ? $this->patchFromRow($pre, $row)['warnings'] : [],
            ];

            if ($action === 'already_enrolled') {
                $alreadyInFirst[] = $this->personRow($match, $group->name, $workshop->name, 'Ya estaba en 1° del ciclo destino.');
                if ($student) {
                    $listStudentIds[$student->id] = true;
                }
            } elseif ($action === 'convert') {
                $converted[] = $this->personRow($match, $group->name, $workshop->name, 'Se pasa desde preinscripción.');
            } elseif ($action === 'place_existing') {
                $placedExisting[] = $this->personRow($match, $group->name, $workshop->name, 'Ya era alumno; se inscribe en 1° destino.');
            } else {
                $createdLate[] = $this->personRow($match, $group->name, $workshop->name, 'No estaba en preinscripción; alta tardía.');
            }

            if ($dryRun) {
                continue;
            }

            try {
                $savedStudent = DB::transaction(function () use (
                    $action,
                    $pre,
                    $student,
                    $row,
                    $year,
                    $group,
                    $workshop,
                    $settings,
                    $originByStudent
                ) {
                    if ($action === 'convert') {
                        return $this->convertPre($pre, $row, $year, $group, $workshop, $settings);
                    }
                    if ($action === 'already_enrolled') {
                        $this->placeInDestination($student, $year, $group, $workshop, isNewAdmission: (bool) $student->enrollments()->where('is_new_admission', true)->exists());
                        if ($pre) {
                            $this->patchAndSyncPre($pre, $row, $student);
                        }

                        return $student;
                    }
                    if ($action === 'place_existing') {
                        $this->placeInDestination($student, $year, $group, $workshop, isNewAdmission: false);
                        $this->retainOrigin($originByStudent->get($student->id), $year->id);

                        return $student;
                    }

                    return $this->createLateStudent($row, $year, $group, $workshop);
                });
                $applied++;
                if ($savedStudent) {
                    $listStudentIds[$savedStudent->id] = true;
                }
            } catch (AdmissionConversionException $exception) {
                $errors[] = $this->skip($match, $exception->getMessage());
            } catch (Throwable $exception) {
                $errors[] = $this->skip($match, $exception->getMessage());
            }
        }

        $reports = $this->buildReports($matches);
        $notOnDestList = $this->peopleNotOnList($destByStudent, $listStudentIds, $matches, $dryRun);
        $notOnOriginList = $originYearId
            ? $this->peopleNotOnList($originByStudent, $listStudentIds, $matches, true)
            : [];

        return [
            'dry_run' => $dryRun,
            'academic_year_id' => $year->id,
            'academic_year_label' => trim($year->year_start.'-'.$year->year_end),
            'rows' => count($rows),
            'in_pre' => count(array_filter($matches, fn (array $match) => $match['pre'] !== null)),
            'matched' => count($converted) + count(array_filter($alreadyInFirst, fn (array $row) => ($row['had_pre'] ?? false))),
            'near_matches' => count(array_filter($planned, fn (array $row) => $row['near_curp'])),
            'applied' => $dryRun ? 0 : $applied,
            'planned' => $planned,
            'skipped' => $skipped,
            'errors' => $errors,
            'manual_adds' => $reports['manual_adds'],
            'not_in_pre_count' => count($reports['manual_adds']),
            'curp_comparisons' => $reports['curp_comparisons'],
            'age_mismatches' => $reports['age_mismatches'],
            'converted' => $converted,
            'created_late' => $createdLate,
            'placed_existing' => $placedExisting,
            'already_in_first' => $alreadyInFirst,
            'dest_first_not_on_list' => $notOnDestList,
            'origin_first_not_on_list' => $notOnOriginList,
        ];
    }

    private function assertGates(AdmissionIntakeSetting $settings): void
    {
        $problems = [];
        if (! $settings->late_intake_enabled) {
            $problems[] = 'El ingreso fuera de fecha está apagado.';
        }
        if (! $settings->allow_convert_without_complete_docs) {
            $problems[] = 'La configuración no permite inscribir con documentos pendientes.';
        }
        if (! $settings->allow_convert_without_payment) {
            $problems[] = 'La configuración no permite inscribir sin pago validado.';
        }
        if ($settings->require_exam_before_convert) {
            $problems[] = 'La configuración exige examen antes de inscribir.';
        }
        if ($problems !== []) {
            throw new RuntimeException(implode(' ', $problems));
        }
    }

    /**
     * @return list<array<string, string>>
     */
    private function readRows(string $path): array
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $book = $reader->load($path);
        $sheet = $book->getSheetByName(self::SHEET) ?: $book->getSheet(0);
        if (! $sheet) {
            throw new RuntimeException('El Excel no tiene la hoja '.self::SHEET.'.');
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
                'group' => $this->cell($sheet, $map['group'], $r),
                'birth' => $this->cell($sheet, $map['birth'], $r),
                'age' => $this->cell($sheet, $map['age'], $r),
                'stat_age' => $this->cell($sheet, $map['stat_age'], $r),
                'gender' => $this->cell($sheet, $map['gender'], $r),
                'tech' => $this->cell($sheet, $map['tech'], $r),
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
                'kinship' => $this->cell($sheet, $map['kinship'], $r),
                'notes' => $this->cell($sheet, $map['notes'], $r),
            ];
            if ($row['curp'] === '' && $row['first'] === '' && $row['paterno'] === '' && $row['group'] === '') {
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

        if ($newLayout) {
            return [
                'first' => 'C',
                'paterno' => 'D',
                'materno' => 'E',
                'curp' => 'G',
                'group' => 'H',
                'birth' => 'I',
                'age' => 'J',
                'stat_age' => 'L',
                'gender' => 'M',
                'tech' => 'N',
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
                'notes' => 'AA',
            ];
        }

        return [
            'first' => 'B',
            'paterno' => 'C',
            'materno' => 'D',
            'curp' => 'F',
            'group' => 'G',
            'birth' => 'H',
            'age' => 'I',
            'stat_age' => 'K',
            'gender' => 'L',
            'tech' => 'M',
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
            'notes' => 'Z',
        ];
    }

    private function cell($sheet, string $column, int $row): string
    {
        return trim((string) $sheet->getCell($column.$row)->getFormattedValue());
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  Collection<int, PreEnrollment>  $pres
     * @return list<array{row: array<string, string>, pre: ?PreEnrollment, student: ?Student, near: bool, near_student: bool, reason: string}>
     */
    private function matchRows(array $rows, Collection $pres): array
    {
        $byCurp = [];
        foreach ($pres as $pre) {
            $curp = strtoupper(trim((string) $pre->curp));
            $byCurp[$curp][] = $pre;
        }

        $used = [];
        $matches = [];
        $pendingNear = [];

        foreach ($rows as $row) {
            if ($row['curp'] === '') {
                $matches[] = $this->emptyMatch($row, 'La fila no trae CURP.');

                continue;
            }

            $found = $byCurp[$row['curp']] ?? [];
            $found = array_values(array_filter($found, fn (PreEnrollment $pre) => ! isset($used[$pre->id])));
            if (count($found) > 1) {
                $matches[] = $this->emptyMatch($row, 'Hay más de una preinscripción con esa CURP.');

                continue;
            }
            if (count($found) === 1) {
                $used[$found[0]->id] = true;
                $matches[] = [
                    'row' => $row,
                    'pre' => $found[0],
                    'student' => null,
                    'near' => false,
                    'near_student' => false,
                    'reason' => '',
                ];

                continue;
            }

            $pendingNear[] = $row;
        }

        foreach ($pendingNear as $row) {
            $candidates = [];
            foreach ($pres as $pre) {
                if (isset($used[$pre->id])) {
                    continue;
                }
                $curp = strtoupper(trim((string) $pre->curp));
                if (abs(strlen($curp) - strlen($row['curp'])) > 1) {
                    continue;
                }
                if (levenshtein($curp, $row['curp']) !== 1) {
                    continue;
                }
                if ($this->normalizeName($this->excelName($row)) !== $this->normalizeName($this->preName($pre))) {
                    continue;
                }
                $candidates[] = $pre;
            }

            if (count($candidates) === 1) {
                $used[$candidates[0]->id] = true;
                $matches[] = [
                    'row' => $row,
                    'pre' => $candidates[0],
                    'student' => null,
                    'near' => true,
                    'near_student' => false,
                    'reason' => '',
                ];

                continue;
            }

            $matches[] = $this->emptyMatch(
                $row,
                count($candidates) > 1
                    ? 'La CURP parecida coincide con más de una preinscripción.'
                    : 'No hay preinscripción con esa CURP.'
            );
        }

        return $matches;
    }

    /**
     * @param  list<array{row: array<string, string>, pre: ?PreEnrollment, student: ?Student, near: bool, near_student: bool, reason: string}>  $matches
     * @param  Collection<int, Student>  $students
     */
    private function attachStudents(array &$matches, Collection $students): void
    {
        $byCurp = [];
        foreach ($students as $student) {
            $curp = strtoupper(trim((string) ($student->profile?->national_id ?? '')));
            if ($curp === '') {
                continue;
            }
            $byCurp[$curp][] = $student;
        }

        $used = [];
        foreach ($matches as &$match) {
            if ($match['pre']?->converted_student_id) {
                $converted = $students->firstWhere('id', (int) $match['pre']->converted_student_id);
                if ($converted) {
                    $match['student'] = $converted;
                    $used[$converted->id] = true;

                    continue;
                }
            }

            $curp = $match['row']['curp'];
            $found = array_values(array_filter(
                $byCurp[$curp] ?? [],
                fn (Student $student) => ! isset($used[$student->id])
            ));
            if (count($found) === 1) {
                $match['student'] = $found[0];
                $used[$found[0]->id] = true;
            }
        }
        unset($match);

        foreach ($matches as &$match) {
            if ($match['student'] || $match['row']['curp'] === '') {
                continue;
            }

            $candidates = [];
            foreach ($students as $student) {
                if (isset($used[$student->id]) || ! $student->profile) {
                    continue;
                }
                $curp = strtoupper(trim((string) $student->profile->national_id));
                if (abs(strlen($curp) - strlen($match['row']['curp'])) > 1) {
                    continue;
                }
                if (levenshtein($curp, $match['row']['curp']) !== 1) {
                    continue;
                }
                $studentName = trim($student->profile->first_name.' '.$student->profile->last_name);
                if ($this->normalizeName($this->excelName($match['row'])) !== $this->normalizeName($studentName)) {
                    continue;
                }
                $candidates[] = $student;
            }

            if (count($candidates) === 1) {
                $match['student'] = $candidates[0];
                $match['near_student'] = true;
                $used[$candidates[0]->id] = true;
            }
        }
        unset($match);
    }

    /**
     * @return array{row: array<string, string>, pre: ?PreEnrollment, student: ?Student, near: bool, near_student: bool, reason: string}
     */
    private function emptyMatch(array $row, string $reason): array
    {
        return [
            'row' => $row,
            'pre' => null,
            'student' => null,
            'near' => false,
            'near_student' => false,
            'reason' => $reason,
        ];
    }

    private function convertPre(
        PreEnrollment $pre,
        array $row,
        AcademicYear $year,
        ClassGroup $group,
        Workshop $workshop,
        AdmissionIntakeSetting $settings,
    ): Student {
        $locked = $this->patchAndLockPre($pre, $row);

        $result = $this->converter->convert($locked->fresh(), [
            'academic_year_id' => $year->id,
            'class_group_id' => $group->id,
            'channel' => 'late',
            'force_incomplete_docs' => true,
            'force_incomplete_data' => (bool) $settings->allow_convert_without_complete_data,
            'force_without_payment' => true,
        ]);

        $student = $result['student'];
        $enrollment = $result['enrollment'];
        $this->syncStudent($student, $locked->fresh());

        if ((int) $enrollment->academic_year_id !== (int) $year->id) {
            $this->placeInDestination($student, $year, $group, $workshop, isNewAdmission: true);
        } else {
            if ((int) $enrollment->class_group_id !== (int) $group->id) {
                $enrollment->update([
                    'class_group_id' => $group->id,
                    'placement_status' => 'placed',
                    'placed_at' => $enrollment->placed_at ?? now(),
                ]);
            }
            $this->workshopWriter->upsert(
                studentId: $student->id,
                academicYearId: $year->id,
                workshopId: $workshop->id,
                source: WorkshopEnrollmentSource::Manual,
                status: WorkshopEnrollmentStatus::Assigned,
                notes: 'Lista 1° 26-27',
            );
        }

        return $student;
    }

    private function patchAndLockPre(PreEnrollment $pre, array $row): PreEnrollment
    {
        $locked = PreEnrollment::query()->whereKey($pre->id)->lockForUpdate()->first();
        if (! $locked) {
            throw new RuntimeException('La preinscripción ya no existe.');
        }

        $this->applyPrePatch($locked, $row);
        if ($locked->status === PreEnrollmentStatus::PENDING) {
            $this->process->startInitialReview($locked->fresh());
            $locked->refresh();
        }

        return $locked;
    }

    private function patchAndSyncPre(PreEnrollment $pre, array $row, Student $student): void
    {
        $locked = PreEnrollment::query()->whereKey($pre->id)->lockForUpdate()->first();
        if (! $locked) {
            return;
        }
        $this->applyPrePatch($locked, $row);
        $this->syncStudent($student, $locked->fresh());
    }

    private function applyPrePatch(PreEnrollment $locked, array $row): void
    {
        $patch = $this->patchFromRow($locked, $row);
        if ($patch['attributes'] !== []) {
            $locked->fill($patch['attributes']);
        }
        if ($patch['review_notes'] !== null) {
            $locked->review_notes = $patch['review_notes'];
        }
        $locked->save();
    }

    private function createLateStudent(array $row, AcademicYear $year, ClassGroup $group, Workshop $workshop): Student
    {
        $curp = $row['curp'];
        if (! $this->validCurp($curp)) {
            throw new RuntimeException('La CURP del Excel no es válida para un alta tardía.');
        }
        if (Profile::query()->where('national_id', $curp)->exists()) {
            throw new RuntimeException('Ya existe una persona con esa CURP.');
        }

        $birth = $this->dateFromCurp($curp);
        if (! $birth) {
            throw new RuntimeException('No se pudo leer la fecha de nacimiento de la CURP.');
        }

        $gender = $this->gender($row['gender']) ?? $this->genderFromCurp($curp) ?? 'O';
        $phone = preg_replace('/\D/', '', $row['phone']) ?? '';
        $phone = strlen($phone) === 10 ? $phone : null;

        $address = Address::query()->create([
            'street_type' => $row['street_type'] !== '' ? $row['street_type'] : 'CALLE',
            'street_name' => $row['street'] !== '' ? $row['street'] : 'SIN CALLE',
            'house_number' => $row['exterior'] !== '' ? $row['exterior'] : ($row['interior'] !== '' ? $row['interior'] : 'S/N'),
            'unit_number' => $row['exterior'] !== '' && $row['interior'] !== '' ? $row['interior'] : null,
            'neighborhood_type' => $row['settlement_type'] !== '' ? $row['settlement_type'] : 'COLONIA',
            'neighborhood_name' => $row['settlement'] !== '' ? $row['settlement'] : 'SIN COLONIA',
            'postal_code' => '00000',
            'city' => 'Oaxaca de Juárez',
            'state' => 'Oaxaca',
        ]);

        $profile = Profile::query()->create([
            'national_id' => $curp,
            'first_name' => $row['first'],
            'last_name' => trim($row['paterno'].' '.$row['materno']),
            'birth_date' => $birth,
            'gender' => $gender,
            'phone_number' => $phone,
            'address_id' => $address->id,
        ]);

        $studentData = ['profile_id' => $profile->id];
        if (Schema::hasColumn('students', 'place_of_birth')) {
            $studentData['place_of_birth'] = 'Oaxaca';
        }
        if (Schema::hasColumn('students', 'previous_school')) {
            $studentData['previous_school'] = 'No registrada';
        }
        if (Schema::hasColumn('students', 'current_average')) {
            $studentData['current_average'] = 8;
        }

        $student = Student::query()->create($studentData);

        $this->attachGuardian($student, $row, $phone);
        $this->placeInDestination($student, $year, $group, $workshop, isNewAdmission: true);

        return $student;
    }

    private function attachGuardian(Student $student, array $row, ?string $phone): void
    {
        $guardianCurp = $row['g_curp'];
        if ($guardianCurp === '' || $guardianCurp === $row['curp'] || ! $this->validCurp($guardianCurp)) {
            $guardianCurp = 'TUT'.substr(preg_replace('/[^A-Z0-9]/', '', $row['curp']) ?? $row['curp'], 0, 15);
        }
        if (strlen($guardianCurp) < 4) {
            return;
        }

        $guardianProfile = Profile::query()->firstOrCreate(
            ['national_id' => $guardianCurp],
            [
                'first_name' => $row['g_name'] !== '' ? $row['g_name'] : 'Tutor',
                'last_name' => trim($row['g_paterno'].' '.$row['g_materno']) ?: 'Sin apellido',
                'gender' => 'O',
                'phone_number' => $phone,
            ]
        );

        $kinship = $row['kinship'] !== '' ? $row['kinship'] : 'Tutor';
        $guardian = Guardian::query()->firstOrCreate(
            ['profile_id' => $guardianProfile->id],
            ['Kinship' => $kinship]
        );
        $student->guardians()->syncWithoutDetaching([
            $guardian->id => ['relationship' => $kinship],
        ]);
    }

    private function placeInDestination(
        Student $student,
        AcademicYear $year,
        ClassGroup $group,
        Workshop $workshop,
        bool $isNewAdmission,
    ): Enrollment {
        $enrollment = Enrollment::query()->firstOrNew([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
        ]);
        if ($enrollment->exists && $enrollment->status === EnrollmentStatus::Dropped) {
            throw new RuntimeException('La inscripción del ciclo destino está en baja.');
        }

        $enrollment->fill([
            'class_group_id' => $group->id,
            'status' => EnrollmentStatus::Active,
            'is_new_admission' => $enrollment->exists ? $enrollment->is_new_admission : $isNewAdmission,
            'admission_channel' => $enrollment->admission_channel ?: 'late',
            'placement_status' => 'placed',
            'placed_at' => $enrollment->placed_at ?? now(),
        ]);
        $enrollment->save();

        $this->workshopWriter->upsert(
            studentId: $student->id,
            academicYearId: $year->id,
            workshopId: $workshop->id,
            source: WorkshopEnrollmentSource::Manual,
            status: WorkshopEnrollmentStatus::Assigned,
            notes: 'Lista 1° 26-27',
        );

        return $enrollment;
    }

    private function retainOrigin(?Enrollment $origin, int $destYearId): void
    {
        if (! $origin || (int) $origin->academic_year_id === $destYearId) {
            return;
        }
        if ($origin->status === EnrollmentStatus::Completed) {
            return;
        }

        $origin->update([
            'is_approved' => false,
            'status' => EnrollmentStatus::Completed,
            'promotion_result' => PromotionResult::RETAINED,
        ]);
    }

    /**
     * @return Collection<int, Enrollment>
     */
    private function firstGradeEnrollments(int $yearId, int $gradeId): Collection
    {
        return Enrollment::query()
            ->where('academic_year_id', $yearId)
            ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
            ->whereHas('classGroup', fn ($query) => $query->where('grade_level_id', $gradeId))
            ->with(['student.profile', 'classGroup'])
            ->get()
            ->keyBy('student_id');
    }

    private function originYearId(int $destYearId): ?int
    {
        $period = ReEnrollmentPeriod::query()
            ->where('to_academic_year_id', $destYearId)
            ->orderByDesc('id')
            ->first();
        if ($period) {
            return (int) $period->from_academic_year_id;
        }

        $dest = AcademicYear::query()->find($destYearId);
        if (! $dest) {
            return null;
        }

        $originId = AcademicYear::query()->where('year_end', $dest->year_start)->value('id');

        return $originId ? (int) $originId : null;
    }

    /**
     * @param  Collection<int, Enrollment>  $enrollments
     * @param  array<int, true>  $listStudentIds
     * @param  list<array{row: array<string, string>, pre: ?PreEnrollment, student: ?Student, near: bool, near_student: bool, reason: string}>  $matches
     * @return list<array{curp: string, name: string, group: string, reason: string}>
     */
    private function peopleNotOnList(Collection $enrollments, array $listStudentIds, array $matches, bool $includeDryRunIds): array
    {
        $onList = $listStudentIds;
        if ($includeDryRunIds) {
            foreach ($matches as $match) {
                if ($match['student']) {
                    $onList[$match['student']->id] = true;
                }
            }
        }

        $missing = [];
        foreach ($enrollments as $enrollment) {
            if (isset($onList[$enrollment->student_id])) {
                continue;
            }
            $profile = $enrollment->student?->profile;
            $missing[] = [
                'curp' => strtoupper(trim((string) ($profile?->national_id ?? ''))),
                'name' => trim(($profile?->first_name ?? '').' '.($profile?->last_name ?? '')),
                'group' => (string) ($enrollment->classGroup?->name ?? ''),
                'reason' => 'Está en 1° y no aparece en el directorio.',
            ];
        }

        usort($missing, fn (array $left, array $right) => [$left['group'], $left['name']] <=> [$right['group'], $right['name']]);

        return $missing;
    }

    /**
     * @param  array{row: array<string, string>, pre: ?PreEnrollment, student: ?Student, near: bool, near_student: bool, reason: string}  $match
     * @return array{row: string, curp: string, name: string, group: string, tech: string, reason: string, had_pre: bool}
     */
    private function personRow(array $match, string $group, string $tech, string $reason): array
    {
        return [
            'row' => $match['row']['row'],
            'curp' => $match['row']['curp'],
            'name' => $this->excelName($match['row']),
            'group' => $group,
            'tech' => $tech,
            'reason' => $reason,
            'had_pre' => $match['pre'] !== null,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return array{attributes: array<string, mixed>, review_notes: ?string, warnings: list<string>}
     */
    private function patchFromRow(PreEnrollment $pre, array $row): array
    {
        $attributes = [];
        $warnings = [];

        if ($row['first'] !== '' || $row['paterno'] !== '') {
            $attributes['first_name'] = $row['first'];
            $attributes['last_name'] = $row['paterno'];
            $attributes['second_last_name'] = $row['materno'] !== '' ? $row['materno'] : null;
        }

        $gender = $this->gender($row['gender']);
        if ($gender !== null) {
            $attributes['gender'] = $gender;
        } elseif ($row['gender'] !== '') {
            $warnings[] = 'Género no reconocido; se conserva el de la preinscripción.';
        }

        if ($row['street'] !== '' || $row['settlement'] !== '') {
            if ($row['street_type'] !== '') {
                $attributes['street_type'] = $row['street_type'];
            }
            if ($row['street'] !== '') {
                $attributes['street_name'] = $row['street'];
            }
            if ($row['settlement_type'] !== '') {
                $attributes['neighborhood_type'] = $row['settlement_type'];
            }
            if ($row['settlement'] !== '') {
                $attributes['neighborhood_name'] = $row['settlement'];
            }

            if ($row['exterior'] !== '') {
                $attributes['house_number'] = $row['exterior'];
                $attributes['unit_number'] = $row['interior'] !== '' ? $row['interior'] : null;
            } elseif ($row['interior'] !== '') {
                $attributes['house_number'] = $row['interior'];
                $attributes['unit_number'] = null;
                $warnings[] = 'El número venía en interior y exterior estaba vacío; se guardó como número de casa.';
            }
        }

        if ($row['g_name'] !== '' || $row['g_paterno'] !== '') {
            $attributes['guardian_first_name'] = $row['g_name'];
            $attributes['guardian_last_name'] = $row['g_paterno'];
            $attributes['guardian_second_last_name'] = $row['g_materno'] !== '' ? $row['g_materno'] : null;
        }
        if ($row['kinship'] !== '') {
            $attributes['guardian_relationship'] = $row['kinship'];
        }

        $phone = preg_replace('/\D/', '', $row['phone']) ?? '';
        if ($phone !== '' && strlen($phone) === 10) {
            $attributes['guardian_phone'] = $phone;
        } elseif ($row['phone'] !== '') {
            $warnings[] = 'Teléfono de contacto inválido; se conserva el de la preinscripción.';
        }

        if ($row['g_curp'] !== '' && $this->validCurp($row['g_curp'])) {
            $attributes['guardian_curp'] = $row['g_curp'];
        } elseif ($row['g_curp'] !== '') {
            $warnings[] = 'CURP del tutor inválida; se conserva la de la preinscripción.';
        }

        $reviewNotes = null;
        if ($row['notes'] !== '') {
            $line = 'Lista 1° 26-27: '.$row['notes'];
            $existing = trim((string) $pre->review_notes);
            if (! str_contains($existing, $line)) {
                $reviewNotes = $existing === '' ? $line : $existing."\n".$line;
            }
        }

        return [
            'attributes' => $attributes,
            'review_notes' => $reviewNotes,
            'warnings' => $warnings,
        ];
    }

    private function syncStudent(Student $student, PreEnrollment $pre): void
    {
        $student->load('profile.address', 'guardians.profile');
        $profile = $student->profile;
        if (! $profile) {
            return;
        }

        $lastName = trim(implode(' ', array_filter([
            $pre->last_name,
            $pre->second_last_name,
        ])));
        $profile->fill([
            'first_name' => $pre->first_name,
            'last_name' => $lastName !== '' ? $lastName : $pre->last_name,
            'gender' => $pre->gender,
        ]);
        $profile->save();

        $profile->address?->update([
            'street_type' => $pre->street_type,
            'street_name' => $pre->street_name,
            'house_number' => $pre->house_number,
            'unit_number' => $pre->unit_number,
            'neighborhood_type' => $pre->neighborhood_type,
            'neighborhood_name' => $pre->neighborhood_name,
        ]);

        $guardian = $student->guardians->first();
        if (! $guardian || ! $guardian->profile) {
            return;
        }

        $guardianLast = trim(implode(' ', array_filter([
            $pre->guardian_last_name,
            $pre->guardian_second_last_name,
        ])));
        $guardianProfile = $guardian->profile;
        $nextCurp = strtoupper(trim((string) $pre->guardian_curp));
        $currentCurp = strtoupper(trim((string) $guardianProfile->national_id));
        if ($nextCurp !== '' && $nextCurp !== $currentCurp) {
            $taken = Profile::query()
                ->where('national_id', $nextCurp)
                ->whereKeyNot($guardianProfile->id)
                ->exists();
            if (! $taken) {
                $guardianProfile->national_id = $nextCurp;
            }
        }
        $guardianProfile->fill([
            'first_name' => $pre->guardian_first_name,
            'last_name' => $guardianLast !== '' ? $guardianLast : $pre->guardian_last_name,
            'phone_number' => $pre->guardian_phone,
        ]);
        $guardianProfile->save();

        if ($pre->guardian_relationship) {
            $guardian->update(['Kinship' => $pre->guardian_relationship]);
            $student->guardians()->updateExistingPivot($guardian->id, [
                'relationship' => $pre->guardian_relationship,
            ]);
        }
    }

    /**
     * @param  Collection<int, Workshop>  $workshops
     */
    private function resolveWorkshop(string $label, Collection $workshops): ?Workshop
    {
        $key = AdmissionWorkshop::normalize($label);
        if ($key === 'ofimatica' || str_contains($key, 'ofimatica')) {
            return $workshops->first(fn (Workshop $workshop) => $workshop->code === Workshop::OFIMATICA_CODE);
        }

        $case = AdmissionWorkshop::fromName($label);
        if ($case) {
            return $workshops->first(fn (Workshop $workshop) => $workshop->code === $case->code());
        }

        foreach (AdmissionWorkshop::cases() as $option) {
            $needle = AdmissionWorkshop::normalize($option->value);
            if ($key !== '' && (str_contains($key, $needle) || str_contains($needle, $key))) {
                return $workshops->first(fn (Workshop $workshop) => $workshop->code === $option->code());
            }
        }

        return $workshops->first(fn (Workshop $workshop) => AdmissionWorkshop::normalize((string) $workshop->name) === $key
            || AdmissionWorkshop::normalize((string) $workshop->code) === $key);
    }

    private function groupLetter(string $raw): ?string
    {
        $raw = preg_replace('/^1\s*°\s*/u', '', trim($raw)) ?? trim($raw);
        $raw = strtoupper(trim($raw));

        return preg_match('/^[A-H]$/', $raw) === 1 ? $raw : null;
    }

    private function gender(string $raw): ?string
    {
        $key = AdmissionWorkshop::normalize($raw);

        return match ($key) {
            'mujer', 'femenino', 'f' => 'F',
            'hombre', 'masculino', 'm' => 'M',
            default => null,
        };
    }

    private function genderFromCurp(string $curp): ?string
    {
        $mark = strtoupper(substr($curp, 10, 1));

        return match ($mark) {
            'H' => 'M',
            'M' => 'F',
            default => null,
        };
    }

    private function validCurp(string $curp): bool
    {
        return preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/', $curp) === 1;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function excelName(array $row): string
    {
        return trim($row['first'].' '.$row['paterno'].' '.$row['materno']);
    }

    private function preName(PreEnrollment $pre): string
    {
        return trim($pre->first_name.' '.$pre->last_name.' '.$pre->second_last_name);
    }

    private function normalizeName(string $value): string
    {
        $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtoupper($value, 'UTF-8')) ?: $value;

        return preg_replace('/[^A-Z0-9]/', '', $folded) ?? '';
    }

    /**
     * @param  array{row: array<string, string>, pre: ?PreEnrollment, student: ?Student, near: bool, near_student: bool, reason: string}  $match
     * @return array{row: string, curp: string, name: string, group: string, tech: string, reason: string}
     */
    private function skip(array $match, string $reason): array
    {
        return [
            'row' => $match['row']['row'],
            'curp' => $match['row']['curp'],
            'name' => $this->excelName($match['row']),
            'group' => $match['row']['group'],
            'tech' => $match['row']['tech'],
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<array{row: array<string, string>, pre: ?PreEnrollment, student: ?Student, near: bool, near_student: bool, reason: string}>  $matches
     * @return array{
     *   manual_adds: list<array{row: string, curp: string, name: string, group: string, tech: string, reason: string}>,
     *   curp_comparisons: list<array{name: string, excel_curp: string, db_curp: string, decision: string}>,
     *   age_mismatches: list<array{name: string, excel_birth: string, excel_age: string, curp_birth: string, curp_age: string, kept: string}>
     * }
     */
    private function buildReports(array $matches): array
    {
        $manual = [];
        $curps = [];
        $ages = [];

        foreach ($matches as $match) {
            $row = $match['row'];
            $pre = $match['pre'];

            if ($pre === null && str_contains($match['reason'], 'No hay preinscripción')) {
                $manual[] = $this->skip($match, $match['reason']);
            }

            $dbCurp = $pre
                ? strtoupper(trim((string) $pre->curp))
                : strtoupper(trim((string) ($match['student']?->profile?->national_id ?? '')));
            if ($dbCurp !== '' && $dbCurp !== $row['curp']) {
                $curps[] = [
                    'name' => $this->excelName($row),
                    'excel_curp' => $row['curp'],
                    'db_curp' => $dbCurp,
                    'decision' => 'Se quedó la CURP de la base',
                ];
            }

            $age = $this->ageMismatch($row, $pre);
            if ($age !== null) {
                $ages[] = $age;
            }
        }

        return [
            'manual_adds' => $manual,
            'curp_comparisons' => $curps,
            'age_mismatches' => $ages,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @return array{name: string, excel_birth: string, excel_age: string, curp_birth: string, curp_age: string, kept: string}|null
     */
    private function ageMismatch(array $row, ?PreEnrollment $pre): ?array
    {
        $curpDate = $this->dateFromCurp($row['curp']);
        $excelDate = $this->parseExcelDate($row['birth'] ?? '');
        $excelAge = trim($row['age'] ?? '');
        $statAge = trim($row['stat_age'] ?? '');
        $curpAge = $curpDate ? (string) Carbon::parse($curpDate)->diff(Carbon::today())->y : '';

        $birthDiffers = $excelDate !== null && $curpDate !== null && $excelDate !== $curpDate;
        $ageDiffers = ($excelAge !== '' && $curpAge !== '' && $excelAge !== $curpAge)
            || ($statAge !== '' && $curpAge !== '' && $statAge !== $curpAge);
        if (! $birthDiffers && ! $ageDiffers) {
            return null;
        }

        $kept = 'No se cambió: no hay preinscripción';
        if ($pre && $pre->birth_date) {
            $kept = 'Se quedó la fecha de la base ('.$this->displayDate(substr((string) $pre->birth_date, 0, 10)).')';
        } elseif (! $pre) {
            $kept = 'Alta tardía: se usó la fecha de la CURP ('.($curpDate ? $this->displayDate($curpDate) : '—').')';
        }

        return [
            'name' => $this->excelName($row),
            'excel_birth' => $excelDate ? $this->displayDate($excelDate) : ($row['birth'] !== '' ? $row['birth'] : '—'),
            'excel_age' => $this->excelAgeLabel($excelAge, $statAge),
            'curp_birth' => $curpDate ? $this->displayDate($curpDate) : '—',
            'curp_age' => $curpAge !== '' ? $curpAge : '—',
            'kept' => $kept,
        ];
    }

    private function dateFromCurp(string $curp): ?string
    {
        if (! preg_match('/^[A-Z]{4}(\d{2})(\d{2})(\d{2})/', $curp, $matches)) {
            return null;
        }

        $year = (int) $matches[1];
        $year += $year <= (int) now()->format('y') ? 2000 : 1900;
        $month = (int) $matches[2];
        $day = (int) $matches[3];
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function parseExcelDate(string $value): ?string
    {
        $value = trim($value);
        if ($value !== '' && is_numeric($value) && (float) $value > 20000) {
            try {
                return ExcelDate::excelToDateTimeObject((float) $value)->format('Y-m-d');
            } catch (Throwable) {
                return null;
            }
        }

        if (! preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $value, $matches)) {
            return null;
        }

        $day = (int) $matches[1];
        $month = (int) $matches[2];
        $year = (int) $matches[3];
        if (! checkdate($month, $day, $year)) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }

    private function excelAgeLabel(string $age, string $statAge): string
    {
        if ($age === '' && $statAge === '') {
            return '—';
        }
        if ($statAge === '' || $statAge === $age) {
            return $age !== '' ? $age : $statAge;
        }
        if ($age === '') {
            return 'estadística '.$statAge;
        }

        return $age.' (estadística '.$statAge.')';
    }

    private function displayDate(string $iso): string
    {
        $date = Carbon::parse($iso);

        return $date->format('d/m/Y');
    }
}
