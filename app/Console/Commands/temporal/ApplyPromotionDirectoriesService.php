<?php

namespace App\Console\Commands\temporal;

use App\Enums\EnrollmentStatus;
use App\Enums\PassedCycleSource;
use App\Enums\PromotionResult;
use App\Enums\ReEnrollmentEventAction;
use App\Enums\ReEnrollmentProcessStep;
use App\Enums\ReEnrollmentValidationStatus;
use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Profile;
use App\Models\School\ReEnrollmentApplication;
use App\Models\School\ReEnrollmentPeriod;
use App\Models\Workshop;
use App\Models\WorkshopEnrollment;
use App\Services\WorkshopEnrollmentWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use RuntimeException;

class ApplyPromotionDirectoriesService
{
    /**
     * Claves de tecnología del directorio de 3°, confirmadas con el directorio 2025-2026 de los mismos alumnos.
     *
     * @var array<string, string>
     */
    private const DEMO_CURPS = [
        'EADO130920HOCLNS03',
        'EAUO120615HOCLNS02',
    ];

    private const DEMO_TUTOR_CURP = 'EJTU850101HOCLNS01';

    private const TECH_CODES = [
        '6031' => 'OFIMATICA',
        '3071' => 'CONFECCION',
        '3021' => 'MAQUINAS',
        '3011' => 'DISENO',
        '5021' => 'INFORMATICA',
    ];

    public function __construct(
        private readonly WorkshopEnrollmentWriter $workshopWriter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function apply(string $secondPath, string $thirdPath, bool $dryRun = true): array
    {
        foreach ([$secondPath, $thirdPath] as $path) {
            if (! is_file($path)) {
                throw new RuntimeException('No se encontró el directorio: '.$path);
            }
        }

        $period = ReEnrollmentPeriod::query()
            ->where('status', 'open')
            ->orderByDesc('id')
            ->first();
        if (! $period) {
            throw new RuntimeException('No hay un periodo de reinscripción abierto.');
        }
        $alreadyExecuted = $period->promotion_executed_at !== null;
        $removedDemo = $this->removeDemoStudents($dryRun);

        $grades = GradeLevel::query()->whereIn('name', ['1°', '2°', '3°'])->get()->keyBy('name');
        foreach (['2°', '3°'] as $name) {
            if (! $grades->has($name)) {
                throw new RuntimeException('No existe el grado '.$name.' en el catálogo.');
            }
        }

        $secondRows = $this->readSecond($secondPath);
        $thirdRows = $this->readThird($thirdPath);
        $rows = array_merge($secondRows, $thirdRows);

        $profiles = Profile::query()->with('student')->whereNotNull('national_id')->get();
        $byCurp = [];
        foreach ($profiles as $profile) {
            $byCurp[strtoupper(trim((string) $profile->national_id))][] = $profile;
        }

        $applications = $period->applications()->get()->keyBy('student_id');
        $matches = $this->matchRows($rows, $byCurp);
        $workshops = $this->workshopsByCode();

        $placed = [];
        $skipped = [];
        $errors = [];
        $applied = 0;
        $usedStudentIds = [];

        foreach ($matches as $match) {
            if ($match['profile'] === null) {
                $skipped[] = $this->issue($match, $match['reason']);

                continue;
            }

            /** @var Profile $profile */
            $profile = $match['profile'];
            $student = $profile->student;
            if (! $student) {
                $skipped[] = $this->issue($match, 'La CURP tiene perfil, pero no hay alumno.');

                continue;
            }

            $letter = $this->groupLetter($match['row']['group']);
            $grade = $grades->get($match['row']['target_grade']);
            if (! $letter || ! $grade) {
                $skipped[] = $this->issue($match, 'El grupo '.$match['row']['group'].' no es válido.');

                continue;
            }

            $workshop = null;
            $workshopName = null;
            if ($match['row']['tech'] !== '') {
                $code = self::TECH_CODES[$match['row']['tech']] ?? null;
                if ($code === null) {
                    $skipped[] = $this->issue($match, 'La tecnología '.$match['row']['tech'].' no está en el catálogo.');

                    continue;
                }
                $workshop = $workshops[$code] ?? null;
                $workshopName = $workshop?->name ?? $this->workshopName($code);
                if (! $workshop && ! $dryRun) {
                    $workshop = $this->ensureWorkshop($code);
                    if ($workshop) {
                        $workshops[$workshop->code] = $workshop;
                        $workshopName = $workshop->name;
                    }
                }
            }

            $origin = Enrollment::query()
                ->with('classGroup.gradeLevel')
                ->where('student_id', $student->id)
                ->where('academic_year_id', $period->from_academic_year_id)
                ->orderByRaw("case when status = 'active' then 0 else 1 end")
                ->first();

            $application = $applications->get($student->id);
            if ($application && $application->status === ReEnrollmentValidationStatus::REJECTED) {
                $skipped[] = $this->issue($match, 'La reinscripción está rechazada.');

                continue;
            }

            $originGrade = $origin?->classGroup?->gradeLevel?->name;
            $result = $this->promotionResult($originGrade, $match['row']['target_grade']);
            $usedStudentIds[$student->id] = true;
            $placed[] = [
                'name' => $this->excelName($match['row']),
                'curp' => $profile->national_id,
                'excel_curp' => $match['row']['curp'],
                'near_curp' => $match['near'],
                'from_grade' => $originGrade,
                'grade' => $match['row']['target_grade'],
                'group' => $letter,
                'workshop' => $workshopName,
                'result' => $result->value,
                'tech' => $match['row']['tech'],
            ];

            if ($dryRun) {
                continue;
            }

            try {
                DB::transaction(function () use ($period, $profile, $student, $match, $grade, $letter, $workshop, $origin, $application, $result) {
                    $this->syncProfile($profile, $match['row']);
                    $group = $this->ensureGroup($period->to_academic_year_id, $grade->id, $letter);

                    if ($origin && $origin->status !== EnrollmentStatus::Completed) {
                        $origin->update([
                            'is_approved' => $result === PromotionResult::PROMOTED,
                            'status' => EnrollmentStatus::Completed,
                            'promotion_result' => $result,
                        ]);
                    }

                    $destination = Enrollment::query()->firstOrNew([
                        'student_id' => $student->id,
                        'academic_year_id' => $period->to_academic_year_id,
                    ]);
                    if ($destination->exists && $destination->status === EnrollmentStatus::Dropped) {
                        throw new RuntimeException('La inscripción del ciclo destino está en baja.');
                    }
                    $destination->fill([
                        'class_group_id' => $group->id,
                        'status' => EnrollmentStatus::Active,
                        'is_new_admission' => false,
                        'promotion_result' => $result,
                    ]);
                    $destination->save();

                    if ($application) {
                        $application->update([
                            'status' => ReEnrollmentValidationStatus::VALIDATED,
                            'passed_cycle' => $result === PromotionResult::PROMOTED,
                            'passed_cycle_source' => PassedCycleSource::MANUAL,
                            'target_class_group_id' => $group->id,
                        ]);
                    }

                    if ($workshop) {
                        $this->workshopWriter->upsert(
                            studentId: $student->id,
                            academicYearId: $period->to_academic_year_id,
                            workshopId: $workshop->id,
                            source: WorkshopEnrollmentSource::Manual,
                            status: WorkshopEnrollmentStatus::Assigned,
                            notes: 'Directorio '.$match['row']['target_grade'].' 26-27',
                        );
                    }
                });
                $applied++;
            } catch (\Throwable $exception) {
                $errors[] = $this->issue($match, $exception->getMessage());
            }
        }

        $outside = $this->closeOutsideDirectories($period, $usedStudentIds, $dryRun, $errors);

        if (! $dryRun && $errors === [] && ! $alreadyExecuted) {
            $summary = [
                'source' => 'directorios-2-y-3',
                'placed' => count($placed),
                'graduated' => count($outside['graduated']),
                'pending' => count($outside['needs_review']),
                'applied' => $applied + $outside['applied'],
            ];
            $period->update([
                'promotion_executed_at' => now(),
                'last_promotion_summary' => $summary,
                'current_step' => $period->keep_current_groups
                    ? ReEnrollmentProcessStep::COMPLETED
                    : ReEnrollmentProcessStep::GROUPS,
            ]);
            $period->events()->create([
                'action' => ReEnrollmentEventAction::PROMOTION_EXECUTED,
                'user_id' => null,
                'summary' => $summary,
            ]);
        }

        $reports = $this->buildReports($matches);

        return [
            'dry_run' => $dryRun,
            'period_id' => $period->id,
            'period_name' => $period->name,
            'from_academic_year_id' => $period->from_academic_year_id,
            'to_academic_year_id' => $period->to_academic_year_id,
            'promotion_already_executed' => $alreadyExecuted,
            'rows' => count($rows),
            'placed' => count($placed),
            'applied' => $dryRun ? 0 : $applied + $outside['applied'],
            'planned' => $placed,
            'skipped' => $skipped,
            'errors' => $errors,
            'graduated' => $outside['graduated'],
            'retained' => $outside['retained'],
            'needs_review' => $outside['needs_review'],
            'removed_demo' => $removedDemo,
            ...$reports,
        ];
    }

    /**
     * @return list<array<string, string>>
     */
    private function readSecond(string $path): array
    {
        $sheet = $this->sheet($path, 'DIRECTORIO');
        $rows = [];
        for ($r = 5; $r <= $sheet->getHighestRow(); $r++) {
            $row = [
                'row' => (string) $r,
                'file' => '2°',
                'target_grade' => '2°',
                'first' => $this->cell($sheet, 'B', $r),
                'paterno' => $this->cell($sheet, 'C', $r),
                'materno' => $this->cell($sheet, 'D', $r),
                'full_name' => $this->cell($sheet, 'E', $r),
                'curp' => strtoupper($this->cell($sheet, 'F', $r)),
                'group' => $this->cell($sheet, 'G', $r),
                'birth' => $this->cell($sheet, 'H', $r),
                'age' => $this->cell($sheet, 'I', $r),
                'gender' => $this->cell($sheet, 'J', $r),
                'tech' => '',
                'street_type' => '',
                'street' => '',
                'interior' => '',
                'exterior' => '',
                'settlement_type' => '',
                'settlement' => '',
                'g_paterno' => '',
                'g_materno' => '',
                'g_name' => '',
            ];
            if ($row['curp'] === '' && $row['first'] === '' && $row['group'] === '') {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @return list<array<string, string>>
     */
    private function readThird(string $path): array
    {
        $general = $this->sheet($path, 'DATOS GENERALES');
        $base = $this->sheet($path, 'BASE DATOS');
        $extra = [];
        for ($r = 5; $r <= $base->getHighestRow(); $r++) {
            $curp = strtoupper($this->cell($base, 'E', $r));
            if ($curp === '') {
                continue;
            }
            $extra[$curp] = [
                'first' => $this->cell($base, 'D', $r),
                'paterno' => $this->cell($base, 'B', $r),
                'materno' => $this->cell($base, 'C', $r),
                'group' => $this->cell($base, 'F', $r),
                'birth' => $this->cell($base, 'J', $r),
                'age' => $this->cell($base, 'K', $r),
                'gender' => $this->cell($base, 'L', $r),
                'street_type' => $this->cell($base, 'M', $r),
                'street' => $this->cell($base, 'N', $r),
                'interior' => $this->cell($base, 'O', $r),
                'exterior' => $this->cell($base, 'P', $r),
                'settlement_type' => $this->cell($base, 'Q', $r),
                'settlement' => $this->cell($base, 'R', $r),
                'g_paterno' => $this->cell($base, 'S', $r),
                'g_materno' => $this->cell($base, 'T', $r),
                'g_name' => $this->cell($base, 'U', $r),
            ];
        }

        $rows = [];
        for ($r = 5; $r <= $general->getHighestRow(); $r++) {
            $curp = strtoupper($this->cell($general, 'D', $r));
            $more = $extra[$curp] ?? [];
            $row = [
                'row' => (string) $r,
                'file' => '3°',
                'target_grade' => '3°',
                'first' => $more['first'] ?? '',
                'paterno' => $more['paterno'] ?? '',
                'materno' => $more['materno'] ?? '',
                'full_name' => $this->cell($general, 'C', $r),
                'curp' => $curp,
                'group' => $this->cell($general, 'E', $r) ?: ($more['group'] ?? ''),
                'birth' => $this->cell($general, 'G', $r) ?: ($more['birth'] ?? ''),
                'age' => $this->cell($general, 'H', $r) ?: ($more['age'] ?? ''),
                'gender' => $this->cell($general, 'I', $r) ?: ($more['gender'] ?? ''),
                'tech' => $this->cell($general, 'F', $r),
                'street_type' => $more['street_type'] ?? '',
                'street' => $more['street'] ?? '',
                'interior' => $more['interior'] ?? '',
                'exterior' => $more['exterior'] ?? '',
                'settlement_type' => $more['settlement_type'] ?? '',
                'settlement' => $more['settlement'] ?? '',
                'g_paterno' => $more['g_paterno'] ?? '',
                'g_materno' => $more['g_materno'] ?? '',
                'g_name' => $more['g_name'] ?? '',
            ];
            if ($row['curp'] === '' && $row['full_name'] === '' && $row['group'] === '') {
                continue;
            }
            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, list<Profile>>  $byCurp
     * @param  list<array<string, string>>  $rows
     * @return list<array{row: array<string, string>, profile: ?Profile, near: bool, reason: string}>
     */
    private function matchRows(array $rows, array $byCurp): array
    {
        $used = [];
        $matches = [];
        $pending = [];

        foreach ($rows as $row) {
            if ($row['curp'] === '') {
                $matches[] = ['row' => $row, 'profile' => null, 'near' => false, 'reason' => 'La fila no trae CURP.'];

                continue;
            }
            $found = array_values(array_filter(
                $byCurp[$row['curp']] ?? [],
                fn (Profile $profile) => ! isset($used[$profile->id])
            ));
            if (count($found) > 1) {
                $matches[] = ['row' => $row, 'profile' => null, 'near' => false, 'reason' => 'Hay más de un perfil con esa CURP.'];

                continue;
            }
            if (count($found) === 1) {
                $used[$found[0]->id] = true;
                $matches[] = ['row' => $row, 'profile' => $found[0], 'near' => false, 'reason' => ''];

                continue;
            }
            $pending[] = $row;
        }

        $pool = [];
        foreach ($byCurp as $list) {
            foreach ($list as $profile) {
                if (! isset($used[$profile->id])) {
                    $pool[] = $profile;
                }
            }
        }

        foreach ($pending as $row) {
            $candidates = [];
            foreach ($pool as $profile) {
                if (isset($used[$profile->id])) {
                    continue;
                }
                $curp = strtoupper(trim((string) $profile->national_id));
                if (abs(strlen($curp) - strlen($row['curp'])) > 1 || levenshtein($curp, $row['curp']) !== 1) {
                    continue;
                }
                if ($this->normalizeName($this->excelName($row)) !== $this->normalizeName(trim($profile->first_name.' '.$profile->last_name))) {
                    continue;
                }
                $candidates[] = $profile;
            }
            if (count($candidates) === 1) {
                $used[$candidates[0]->id] = true;
                $matches[] = ['row' => $row, 'profile' => $candidates[0], 'near' => true, 'reason' => ''];

                continue;
            }
            $matches[] = [
                'row' => $row,
                'profile' => null,
                'near' => false,
                'reason' => 'No hay alumno con esa CURP.',
            ];
        }

        return $matches;
    }

    /**
     * @param  array<string, string>  $row
     */
    private function syncProfile(Profile $profile, array $row): void
    {
        $first = $row['first'];
        $last = trim($row['paterno'].' '.$row['materno']);
        if ($first !== '' || $last !== '') {
            $profile->first_name = $first !== '' ? $first : $profile->first_name;
            if ($last !== '') {
                $profile->last_name = $last;
            }
        }
        $gender = $this->gender($row['gender']);
        if ($gender !== null) {
            $profile->gender = $gender;
        }

        $excelDate = $this->parseExcelDate($row['birth']);
        $curpDate = $this->dateFromCurp($row['curp']);
        if ($excelDate !== null && $excelDate === $curpDate) {
            $profile->birth_date = $excelDate;
        }
        $profile->save();

        if ($this->usableStreet($row['street'])) {
            $address = $profile->address;
            if ($address) {
                $address->update([
                    'street_type' => $row['street_type'] !== '' ? $row['street_type'] : $address->street_type,
                    'street_name' => $row['street'],
                    'house_number' => $row['exterior'] !== '' ? $row['exterior'] : ($row['interior'] !== '' ? $row['interior'] : $address->house_number),
                    'unit_number' => $row['exterior'] !== '' ? ($row['interior'] !== '' ? $row['interior'] : null) : $address->unit_number,
                    'neighborhood_type' => $row['settlement_type'] !== '' ? $row['settlement_type'] : $address->neighborhood_type,
                    'neighborhood_name' => $row['settlement'] !== '' ? $row['settlement'] : $address->neighborhood_name,
                ]);
            }
        }
    }

    private function ensureGroup(int $academicYearId, int $gradeLevelId, string $name): ClassGroup
    {
        return ClassGroup::query()->firstOrCreate(
            [
                'academic_year_id' => $academicYearId,
                'grade_level_id' => $gradeLevelId,
                'name' => $name,
            ],
            []
        );
    }

    private function ensureWorkshop(?string $code): ?Workshop
    {
        if ($code === null) {
            return null;
        }

        $names = [
            'OFIMATICA' => 'Ofimática',
            'CONFECCION' => 'Confección del vestido e industria textil',
            'MAQUINAS' => 'Máquinas, herramientas y sistemas de control',
            'DISENO' => 'Diseño Industrial',
            'INFORMATICA' => 'Informática',
        ];
        $attributes = [
            'name' => $names[$code] ?? $code,
            'is_active' => true,
        ];
        if (Schema::hasColumn('workshops', 'is_internal')) {
            $attributes['is_internal'] = $code === Workshop::OFIMATICA_CODE;
        }

        return Workshop::query()->firstOrCreate(['code' => $code], $attributes);
    }

    private function workshopName(string $code): string
    {
        return match ($code) {
            'OFIMATICA' => 'Ofimática',
            'CONFECCION' => 'Confección del vestido e industria textil',
            'MAQUINAS' => 'Máquinas, herramientas y sistemas de control',
            'DISENO' => 'Diseño Industrial',
            'INFORMATICA' => 'Informática',
            default => $code,
        };
    }

    /**
     * @return array<string, Workshop>
     */
    private function workshopsByCode(): array
    {
        $map = [];
        foreach (Workshop::query()->where('is_active', true)->get() as $workshop) {
            if ($workshop->code) {
                $map[$workshop->code] = $workshop;
            }
        }

        return $map;
    }

    private function promotionResult(?string $originGrade, string $targetGrade): PromotionResult
    {
        $origin = match ($originGrade) {
            '1°' => 1,
            '2°' => 2,
            '3°' => 3,
            default => 0,
        };
        $target = match ($targetGrade) {
            '1°' => 1,
            '2°' => 2,
            '3°' => 3,
            default => 0,
        };

        return $target > $origin ? PromotionResult::PROMOTED : PromotionResult::RETAINED;
    }

    /**
     * @param  list<array{row: array<string, string>, profile: ?Profile, near: bool, reason: string}>  $matches
     * @return array{manual_adds: list<array<string, string>>, curp_comparisons: list<array<string, string>>, age_mismatches: list<array<string, string>>}
     */
    private function buildReports(array $matches): array
    {
        $manual = [];
        $curps = [];
        $ages = [];
        foreach ($matches as $match) {
            if ($match['profile'] === null && str_contains($match['reason'], 'No hay alumno')) {
                $manual[] = $this->issue($match, $match['reason']);
            }
            if ($match['near'] && $match['profile']) {
                $curps[] = [
                    'name' => $this->excelName($match['row']),
                    'excel_curp' => $match['row']['curp'],
                    'db_curp' => strtoupper(trim((string) $match['profile']->national_id)),
                    'decision' => 'Se quedó la CURP de la base',
                ];
            }
            $age = $this->ageMismatch($match['row'], $match['profile']);
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
     * Quien no está en ningún directorio queda pendiente de promover.
     * La excepción es 3° con el ciclo aprobado: egresa.
     * Reprobar solo cuenta si el alumno aparece en el directorio de su mismo grado.
     *
     * @param  array<int, bool>  $usedStudentIds
     * @param  list<array<string, string>>  $errors
     * @return array{graduated: list<array<string, string>>, retained: list<array<string, string>>, needs_review: list<array<string, string>>, applied: int}
     */
    private function closeOutsideDirectories(ReEnrollmentPeriod $period, array $usedStudentIds, bool $dryRun, array &$errors): array
    {
        $graduated = [];
        $retained = [];
        $needsReview = [];
        $applied = 0;
        $applications = $period->applications()->with('enrollment.classGroup.gradeLevel', 'student.profile')->get();

        foreach ($applications as $application) {
            if (isset($usedStudentIds[$application->student_id]) || $application->status === ReEnrollmentValidationStatus::REJECTED) {
                continue;
            }

            $origin = $application->enrollment;
            $gradeName = (string) ($origin?->classGroup?->gradeLevel?->name ?? '');
            $letter = (string) ($origin?->classGroup?->name ?? '');
            $profile = $application->student?->profile;
            if (in_array(strtoupper(trim((string) ($profile->national_id ?? ''))), self::DEMO_CURPS, true)) {
                continue;
            }
            $row = [
                'name' => trim(($profile->first_name ?? '').' '.($profile->last_name ?? '')),
                'curp' => strtoupper(trim((string) ($profile->national_id ?? ''))),
                'grade' => $gradeName,
                'group' => $letter,
                'decision' => '',
            ];
            $approved = $origin?->is_approved;

            if ($gradeName === '3°' && $approved === true) {
                $row['decision'] = 'Egresa: la inscripción queda concluida';
                $graduated[] = $row;
                if (! $dryRun && $this->writeClosure($period, $application, $origin, PromotionResult::GRADUATED, null, $errors, $row)) {
                    $applied++;
                }

                continue;
            }

            $row['decision'] = 'Pendiente de promover: no aparece en el directorio de 2° ni en el de 3°';
            $needsReview[] = $row;
        }

        return [
            'graduated' => $graduated,
            'retained' => $retained,
            'needs_review' => $needsReview,
            'applied' => $applied,
        ];
    }

    /**
     * @param  list<array<string, string>>  $errors
     * @param  array<string, string>  $row
     */
    private function writeClosure(
        ReEnrollmentPeriod $period,
        ReEnrollmentApplication $application,
        ?Enrollment $origin,
        PromotionResult $result,
        ?GradeLevel $grade,
        array &$errors,
        array $row,
    ): bool {
        if (! $origin) {
            $errors[] = [
                'row' => '',
                'curp' => $row['curp'],
                'name' => $row['name'],
                'reason' => 'No tiene inscripción de origen.',
            ];

            return false;
        }

        try {
            DB::transaction(function () use ($period, $application, $origin, $result, $grade) {
                $targetGroupId = null;
                if ($result === PromotionResult::RETAINED) {
                    $group = $this->ensureGroup($period->to_academic_year_id, $grade->id, $origin->classGroup->name);
                    $targetGroupId = $group->id;
                    $destination = Enrollment::query()->firstOrNew([
                        'student_id' => $origin->student_id,
                        'academic_year_id' => $period->to_academic_year_id,
                    ]);
                    if ($destination->exists && $destination->status === EnrollmentStatus::Dropped) {
                        throw new RuntimeException('La inscripción del ciclo destino está en baja.');
                    }
                    $destination->fill([
                        'class_group_id' => $group->id,
                        'status' => EnrollmentStatus::Active,
                        'is_new_admission' => false,
                        'promotion_result' => PromotionResult::RETAINED,
                    ]);
                    $destination->save();
                    $this->copyWorkshop($origin, $period->to_academic_year_id);
                }

                if ($origin->status !== EnrollmentStatus::Completed) {
                    $origin->update([
                        'is_approved' => $result === PromotionResult::GRADUATED,
                        'status' => EnrollmentStatus::Completed,
                        'promotion_result' => $result,
                    ]);
                }

                $application->update([
                    'status' => ReEnrollmentValidationStatus::VALIDATED,
                    'passed_cycle' => $result === PromotionResult::GRADUATED,
                    'passed_cycle_source' => PassedCycleSource::MANUAL,
                    'target_class_group_id' => $targetGroupId,
                ]);
            });
        } catch (\Throwable $exception) {
            $errors[] = [
                'row' => '',
                'curp' => $row['curp'],
                'name' => $row['name'],
                'reason' => $exception->getMessage(),
            ];

            return false;
        }

        return true;
    }

    /**
     * Ejemplo Alumno Uno y Ejemplo Alumno Dos son registros de prueba.
     * Se borra a cada uno con su inscripción y lo demás que cuelga del alumno.
     * El tutor de ejemplo se borra solo si ya no queda ligado a ningún otro alumno.
     *
     * @return list<array{name: string, curp: string, grade: string, group: string, decision: string}>
     */
    private function removeDemoStudents(bool $dryRun): array
    {
        $profiles = Profile::query()->whereIn('national_id', self::DEMO_CURPS)->orderBy('national_id')->get();
        $studentIds = [];
        $removed = [];
        foreach ($profiles as $profile) {
            if ($profile->student) {
                $studentIds[] = $profile->student->id;
            }
            $removed[] = $this->demoRemovalRow(
                trim($profile->first_name.' '.$profile->last_name),
                strtoupper((string) $profile->national_id),
                $dryRun
                    ? 'Se eliminará el alumno y su inscripción, asistencia y lecturas.'
                    : 'Se eliminó el alumno y su inscripción, asistencia y lecturas.',
            );
        }

        $tutor = $this->demoTutorRow($studentIds, $dryRun);
        if ($tutor !== null) {
            $removed[] = $tutor;
        }
        if ($removed === [] || $dryRun) {
            return $removed;
        }

        DB::transaction(function () use ($profiles, $studentIds) {
            foreach ($profiles as $profile) {
                $this->deleteStudentContent($profile);
            }
            $this->deleteDemoTutor($studentIds);
        });

        return $removed;
    }

    /**
     * @param  list<int>  $demoStudentIds
     * @return array{name: string, curp: string, grade: string, group: string, decision: string}|null
     */
    private function demoTutorRow(array $demoStudentIds, bool $dryRun): ?array
    {
        $profile = Profile::query()->where('national_id', self::DEMO_TUTOR_CURP)->first();
        if (! $profile?->guardian) {
            return null;
        }
        $otherStudents = DB::table('guardian_student')
            ->where('guardian_id', $profile->guardian->id)
            ->when($demoStudentIds !== [], fn ($query) => $query->whereNotIn('student_id', $demoStudentIds))
            ->count();
        if ($otherStudents > 0) {
            return null;
        }

        return $this->demoRemovalRow(
            trim($profile->first_name.' '.$profile->last_name),
            self::DEMO_TUTOR_CURP,
            $dryRun
                ? 'Se eliminará el tutor de ejemplo porque ya no queda ligado a otro alumno.'
                : 'Se eliminó el tutor de ejemplo porque ya no quedaba ligado a otro alumno.',
        );
    }

    /**
     * @param  list<int>  $demoStudentIds
     */
    private function deleteDemoTutor(array $demoStudentIds): void
    {
        $profile = Profile::query()->where('national_id', self::DEMO_TUTOR_CURP)->first();
        $guardian = $profile?->guardian;
        if (! $profile || ! $guardian) {
            return;
        }
        $otherStudents = DB::table('guardian_student')
            ->where('guardian_id', $guardian->id)
            ->when($demoStudentIds !== [], fn ($query) => $query->whereNotIn('student_id', $demoStudentIds))
            ->count();
        if ($otherStudents > 0) {
            return;
        }

        DB::table('guardian_student')->where('guardian_id', $guardian->id)->delete();
        $guardian->delete();
        $addressId = $profile->address_id;
        $profile->delete();
        if ($addressId && Schema::hasTable('addresses') && ! Profile::query()->where('address_id', $addressId)->exists()) {
            DB::table('addresses')->where('id', $addressId)->delete();
        }
    }

    private function deleteStudentContent(Profile $profile): void
    {
        $student = $profile->student;
        if ($student) {
            foreach ([
                're_enrollment_applications',
                'workshop_enrollments',
                'enrollments',
                'guardian_student',
                'general_attendances',
                'recent_readings',
                'attendances',
                'incidents',
                'student_permissions',
                'suspensions',
                'id_cards',
                'class_students',
                'absence_requests',
                'student_credential_trackings',
                'print_jobs',
                'nfc_assignments',
            ] as $table) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, 'student_id')) {
                    DB::table($table)->where('student_id', $student->id)->delete();
                }
            }
            $student->delete();
        }

        $addressId = $profile->address_id;
        $profile->delete();
        if ($addressId && Schema::hasTable('addresses') && ! Profile::query()->where('address_id', $addressId)->exists()) {
            DB::table('addresses')->where('id', $addressId)->delete();
        }
    }

    /**
     * @return array{name: string, curp: string, grade: string, group: string, decision: string}
     */
    private function demoRemovalRow(string $name, string $curp, string $decision): array
    {
        return [
            'name' => $name,
            'curp' => $curp,
            'grade' => '',
            'group' => '',
            'decision' => $decision,
        ];
    }

    private function copyWorkshop(Enrollment $origin, int $toAcademicYearId): void
    {
        $current = WorkshopEnrollment::query()
            ->where('student_id', $origin->student_id)
            ->where('academic_year_id', $origin->academic_year_id)
            ->where('status', WorkshopEnrollmentStatus::Assigned)
            ->first();
        if (! $current) {
            return;
        }

        $this->workshopWriter->upsert(
            studentId: $origin->student_id,
            academicYearId: $toAcademicYearId,
            workshopId: (int) $current->workshop_id,
            source: WorkshopEnrollmentSource::Inherited,
            status: WorkshopEnrollmentStatus::Assigned,
            notes: 'Se queda en el mismo grado',
            protectManual: true,
        );
    }

    /**
     * @param  array<string, string>  $row
     * @return array{name: string, excel_birth: string, excel_age: string, curp_birth: string, curp_age: string, kept: string}|null
     */
    private function ageMismatch(array $row, ?Profile $profile): ?array
    {
        $excelDate = $this->parseExcelDate($row['birth']);
        $curpDate = $this->dateFromCurp($row['curp']);
        $curpAge = $curpDate ? (string) Carbon::parse($curpDate)->diff(Carbon::today())->y : '';
        $excelAge = strtok(str_replace(',', '.', $row['age']), '.');
        $excelAge = $excelAge === false ? '' : $excelAge;
        $birthDiffers = $excelDate !== null && $curpDate !== null && $excelDate !== $curpDate;
        $ageDiffers = $excelAge !== '' && $curpAge !== '' && $excelAge !== $curpAge;
        if (! $birthDiffers && ! $ageDiffers) {
            return null;
        }

        $kept = 'No se cambió: no hay alumno';
        if ($profile?->birth_date) {
            $kept = 'Se quedó la fecha de la base ('.Carbon::parse($profile->birth_date)->format('d/m/Y').')';
            if ($excelDate !== null && $excelDate === $curpDate) {
                $kept = 'Se actualizó con la fecha de la CURP ('.Carbon::parse($excelDate)->format('d/m/Y').')';
            }
        }

        return [
            'name' => $this->excelName($row),
            'excel_birth' => $excelDate ? Carbon::parse($excelDate)->format('d/m/Y') : ($row['birth'] !== '' ? $row['birth'] : '—'),
            'excel_age' => $row['age'] !== '' ? $row['age'] : '—',
            'curp_birth' => $curpDate ? Carbon::parse($curpDate)->format('d/m/Y') : '—',
            'curp_age' => $curpAge !== '' ? $curpAge : '—',
            'kept' => $kept,
        ];
    }

    private function sheet(string $path, string $name): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $reader = IOFactory::createReaderForFile($path);
        $reader->setReadDataOnly(true);
        $reader->setLoadSheetsOnly([$name]);
        $sheet = $reader->load($path)->getSheetByName($name);
        if (! $sheet) {
            throw new RuntimeException('El Excel no tiene la hoja '.$name.'.');
        }

        return $sheet;
    }

    private function cell($sheet, string $column, int $row): string
    {
        return trim((string) $sheet->getCell($column.$row)->getFormattedValue());
    }

    private function parseExcelDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (is_numeric($value)) {
            $serial = (float) $value;
            if ($serial < 20000 || $serial > 60000) {
                return null;
            }

            return Date::excelToDateTimeObject($serial)->format('Y-m-d');
        }
        if (! preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $value, $matches)) {
            return null;
        }
        if (! checkdate((int) $matches[2], (int) $matches[1], (int) $matches[3])) {
            return null;
        }

        return sprintf('%04d-%02d-%02d', (int) $matches[3], (int) $matches[2], (int) $matches[1]);
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

    /**
     * @param  array<string, string>  $row
     */
    private function excelName(array $row): string
    {
        $split = trim($row['first'].' '.$row['paterno'].' '.$row['materno']);

        return $split !== '' ? $split : trim($row['full_name']);
    }

    private function normalizeName(string $value): string
    {
        $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtoupper($value, 'UTF-8')) ?: $value;
        $tokens = preg_split('/[^A-Z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        sort($tokens);

        return implode('', $tokens);
    }

    private function groupLetter(string $raw): ?string
    {
        $raw = strtoupper(trim($raw));
        if (preg_match('/([A-H])$/', $raw, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    private function gender(string $raw): ?string
    {
        $key = preg_replace('/[^A-Z]/', '', iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtoupper($raw)) ?: '') ?? '';

        return match ($key) {
            'MUJER', 'FEMENINO', 'F' => 'F',
            'HOMBRE', 'MASCULINO', 'M' => 'M',
            default => null,
        };
    }

    private function usableStreet(string $street): bool
    {
        $street = trim($street);
        if (mb_strlen($street) < 4) {
            return false;
        }

        return ! in_array(mb_strtolower($street), ['la', 'el', 'de', 'de la', 'a'], true);
    }

    /**
     * @param  array{row: array<string, string>, profile: ?Profile, near: bool, reason: string}  $match
     * @return array{row: string, curp: string, name: string, group: string, tech: string, grade: string, reason: string}
     */
    private function issue(array $match, string $reason): array
    {
        return [
            'row' => $match['row']['row'],
            'curp' => $match['row']['curp'],
            'name' => $this->excelName($match['row']),
            'group' => $match['row']['group'],
            'tech' => $match['row']['tech'],
            'grade' => $match['row']['target_grade'],
            'reason' => $reason,
        ];
    }
}
