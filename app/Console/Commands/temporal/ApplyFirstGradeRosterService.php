<?php

namespace App\Console\Commands\temporal;

use App\Enums\AdmissionWorkshop;
use App\Services\ConvertPreEnrollmentToStudentService;
use App\Services\PreEnrollmentProcessService;
use App\Services\WorkshopEnrollmentWriter;
use App\Enums\PreEnrollmentStatus;
use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use App\Exceptions\AdmissionConversionException;
use App\Models\AcademicYear;
use App\Models\AdmissionIntakeSetting;
use App\Models\ClassGroup;
use App\Models\GradeLevel;
use App\Models\PreEnrollment;
use App\Models\Profile;
use App\Models\Student;
use App\Models\Workshop;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
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

        $matches = $this->matchRows($rows, $pres);
        $planned = [];
        $skipped = [];
        $applied = 0;
        $errors = [];

        foreach ($matches as $match) {
            if ($match['pre'] === null) {
                $skipped[] = [
                    'row' => $match['row']['row'],
                    'curp' => $match['row']['curp'],
                    'name' => $this->excelName($match['row']),
                    'reason' => $match['reason'],
                ];

                continue;
            }

            /** @var PreEnrollment $pre */
            $pre = $match['pre'];
            $letter = $this->groupLetter($match['row']['group']);
            $group = $letter ? $groups->get($letter) : null;
            $workshop = $this->resolveWorkshop($match['row']['tech'], $workshops);

            if (! $group) {
                $skipped[] = $this->skip($match, 'El grupo '.$match['row']['group'].' no existe en 1° de este ciclo.');

                continue;
            }
            if (! $workshop) {
                $skipped[] = $this->skip($match, 'El taller '.$match['row']['tech'].' no está en el catálogo.');

                continue;
            }
            if ($pre->status === PreEnrollmentStatus::REJECTED) {
                $skipped[] = $this->skip($match, 'La preinscripción está rechazada.');

                continue;
            }

            $patch = $this->patchFromRow($pre, $match['row']);
            $planned[] = [
                'row' => $match['row']['row'],
                'pre_enrollment_id' => $pre->id,
                'folio' => $pre->folio,
                'curp' => $pre->curp,
                'excel_curp' => $match['row']['curp'],
                'near_curp' => $match['near'],
                'name' => $this->excelName($match['row']),
                'group' => $group->name,
                'workshop' => $workshop->name,
                'warnings' => $patch['warnings'],
            ];

            if ($dryRun) {
                continue;
            }

            try {
                DB::transaction(function () use ($pre, $patch, $year, $group, $workshop, $settings) {
                    $locked = PreEnrollment::query()->whereKey($pre->id)->lockForUpdate()->first();
                    if (! $locked) {
                        throw new RuntimeException('La preinscripción ya no existe.');
                    }

                    if ($patch['attributes'] !== []) {
                        $locked->fill($patch['attributes']);
                    }
                    if ($patch['review_notes'] !== null) {
                        $locked->review_notes = $patch['review_notes'];
                    }
                    $locked->save();

                    if ($locked->status === PreEnrollmentStatus::PENDING) {
                        $this->process->startInitialReview($locked->fresh());
                        $locked->refresh();
                    }

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
                });
                $applied++;
            } catch (AdmissionConversionException $exception) {
                $errors[] = $this->skip($match, $exception->getMessage());
            } catch (Throwable $exception) {
                $errors[] = $this->skip($match, $exception->getMessage());
            }
        }

        $reports = $this->buildReports($matches);

        return [
            'dry_run' => $dryRun,
            'academic_year_id' => $year->id,
            'rows' => count($rows),
            'matched' => count($planned),
            'near_matches' => count(array_filter($planned, fn (array $row) => $row['near_curp'])),
            'applied' => $dryRun ? 0 : $applied,
            'planned' => $planned,
            'skipped' => $skipped,
            'errors' => $errors,
            'manual_adds' => $reports['manual_adds'],
            'curp_comparisons' => $reports['curp_comparisons'],
            'age_mismatches' => $reports['age_mismatches'],
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
        $reader->setLoadSheetsOnly([self::SHEET]);
        $sheet = $reader->load($path)->getSheetByName(self::SHEET);
        if (! $sheet) {
            throw new RuntimeException('El Excel no tiene la hoja '.self::SHEET.'.');
        }

        $rows = [];
        $highest = $sheet->getHighestRow();
        for ($r = 5; $r <= $highest; $r++) {
            $row = [
                'row' => (string) $r,
                'first' => $this->cell($sheet, 'B', $r),
                'paterno' => $this->cell($sheet, 'C', $r),
                'materno' => $this->cell($sheet, 'D', $r),
                'curp' => strtoupper($this->cell($sheet, 'F', $r)),
                'group' => $this->cell($sheet, 'G', $r),
                'birth' => $this->cell($sheet, 'H', $r),
                'age' => $this->cell($sheet, 'I', $r),
                'stat_age' => $this->cell($sheet, 'K', $r),
                'gender' => $this->cell($sheet, 'L', $r),
                'tech' => $this->cell($sheet, 'M', $r),
                'street_type' => $this->cell($sheet, 'N', $r),
                'street' => $this->cell($sheet, 'O', $r),
                'interior' => $this->cell($sheet, 'P', $r),
                'exterior' => $this->cell($sheet, 'Q', $r),
                'settlement_type' => $this->cell($sheet, 'R', $r),
                'settlement' => $this->cell($sheet, 'S', $r),
                'g_paterno' => $this->cell($sheet, 'T', $r),
                'g_materno' => $this->cell($sheet, 'U', $r),
                'g_name' => $this->cell($sheet, 'V', $r),
                'g_curp' => strtoupper($this->cell($sheet, 'W', $r)),
                'phone' => $this->cell($sheet, 'X', $r),
                'kinship' => $this->cell($sheet, 'Y', $r),
                'notes' => $this->cell($sheet, 'Z', $r),
            ];
            if ($row['curp'] === '' && $row['first'] === '' && $row['paterno'] === '' && $row['group'] === '') {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private function cell($sheet, string $column, int $row): string
    {
        return trim((string) $sheet->getCell($column.$row)->getFormattedValue());
    }

    /**
     * @param  list<array<string, string>>  $rows
     * @param  Collection<int, PreEnrollment>  $pres
     * @return list<array{row: array<string, string>, pre: ?PreEnrollment, near: bool, reason: string}>
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
                $matches[] = ['row' => $row, 'pre' => null, 'near' => false, 'reason' => 'La fila no trae CURP.'];

                continue;
            }

            $found = $byCurp[$row['curp']] ?? [];
            $found = array_values(array_filter($found, fn (PreEnrollment $pre) => ! isset($used[$pre->id])));
            if (count($found) > 1) {
                $matches[] = ['row' => $row, 'pre' => null, 'near' => false, 'reason' => 'Hay más de una preinscripción con esa CURP.'];

                continue;
            }
            if (count($found) === 1) {
                $used[$found[0]->id] = true;
                $matches[] = ['row' => $row, 'pre' => $found[0], 'near' => false, 'reason' => ''];

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
                $matches[] = ['row' => $row, 'pre' => $candidates[0], 'near' => true, 'reason' => ''];

                continue;
            }

            $matches[] = [
                'row' => $row,
                'pre' => null,
                'near' => false,
                'reason' => count($candidates) > 1
                    ? 'La CURP parecida coincide con más de una preinscripción.'
                    : 'No hay preinscripción con esa CURP.',
            ];
        }

        return $matches;
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
        if ($key === 'ofimatica') {
            return $workshops->first(fn (Workshop $workshop) => $workshop->code === Workshop::OFIMATICA_CODE);
        }

        $case = AdmissionWorkshop::fromName($label);
        if (! $case) {
            return null;
        }

        return $workshops->first(fn (Workshop $workshop) => $workshop->code === $case->code());
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
     * @param  array{row: array<string, string>, pre: ?PreEnrollment, near: bool, reason: string}  $match
     * @return array{row: string, curp: string, name: string, reason: string}
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
     * @param  list<array{row: array<string, string>, pre: ?PreEnrollment, near: bool, reason: string}>  $matches
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

            if ($pre && strtoupper(trim((string) $pre->curp)) !== $row['curp']) {
                $curps[] = [
                    'name' => $this->excelName($row),
                    'excel_curp' => $row['curp'],
                    'db_curp' => strtoupper(trim((string) $pre->curp)),
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
        if (! preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', trim($value), $matches)) {
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
