<?php

namespace App\Console\Commands\temporal;

use App\Enums\EnrollmentStatus;
use App\Enums\PassedCycleSource;
use App\Enums\PromotionResult;
use App\Enums\ReEnrollmentValidationStatus;
use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Address;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Guardian;
use App\Models\Profile;
use App\Models\School\ReEnrollmentApplication;
use App\Models\School\ReEnrollmentPeriod;
use App\Models\Student;
use App\Models\Workshop;
use App\Services\WorkshopEnrollmentWriter;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Inscribe en 2026-2027 a quien aparece en el directorio de su grado y aún no está inscrito:
 * alta tardía si no existe, promoción si viene del grado anterior, repetición si viene del mismo grado.
 */
class EnrollDirectoryMissingService
{
    private const TECH_CODES = [
        '6031' => 'OFIMATICA',
        '3071' => 'CONFECCION',
        '3021' => 'MAQUINAS',
        '3011' => 'DISENO',
        '5021' => 'INFORMATICA',
    ];

    private const GRADE_NUMBER = ['1°' => 1, '2°' => 2, '3°' => 3];

    private const NOT_SPECIFIED = SyncDirectoryDetailsService::NOT_SPECIFIED;

    public function __construct(
        private readonly SyncDirectoryDetailsService $reader,
        private readonly WorkshopEnrollmentWriter $workshopWriter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function enroll(string $path, int $academicYearId, string $gradeName, bool $dryRun = true): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('No se encontró el Excel de directorio.');
        }

        $year = AcademicYear::query()->find($academicYearId);
        if (! $year || (string) $year->year_start !== '2026' || (string) $year->year_end !== '2027') {
            throw new RuntimeException('Este comando solo inscribe en el ciclo 2026-2027.');
        }

        $grade = GradeLevel::query()->where('name', $gradeName)->first();
        if (! $grade || ! isset(self::GRADE_NUMBER[$gradeName])) {
            throw new RuntimeException('No existe el grado '.$gradeName.' en el catálogo.');
        }

        $period = ReEnrollmentPeriod::query()
            ->where('to_academic_year_id', $year->id)
            ->orderByDesc('id')
            ->first();
        $originYearId = $period ? (int) $period->from_academic_year_id : null;

        $groups = ClassGroup::query()
            ->where('academic_year_id', $year->id)
            ->where('grade_level_id', $grade->id)
            ->get()
            ->keyBy(fn (ClassGroup $group) => strtoupper(trim($group->name)));
        $workshops = Workshop::query()->where('is_active', true)->get()->keyBy('code');

        [$sheetName, $rows] = $this->reader->readRows($path);
        $enrolledInGrade = $this->enrolledNames($year->id, $grade->id);

        $created = [];
        $promoted = [];
        $retained = [];
        $already = 0;
        $skipped = [];
        $errors = [];
        $applied = 0;

        foreach ($rows as $row) {
            $curp = $row['curp'];
            $name = $row['student_name'];

            $profile = $curp !== '' ? Profile::query()->with('student')->where('national_id', $curp)->first() : null;
            $student = $profile?->student;

            if ($student && Enrollment::query()
                ->where('student_id', $student->id)
                ->where('academic_year_id', $year->id)
                ->where('status', EnrollmentStatus::Active)
                ->exists()) {
                $already++;

                continue;
            }
            if (! $student && $this->nameIsEnrolled($name, $enrolledInGrade)) {
                $already++;

                continue;
            }

            $letter = $this->groupLetter($row['group']);
            $group = $letter ? $groups->get($letter) : null;
            if (! $group) {
                $skipped[] = $this->issue($row, 'El grupo "'.$row['group'].'" no existe en '.$gradeName.' 2026-2027.');

                continue;
            }
            $workshopCode = self::workshopCode($row['tech']);
            $workshop = $workshopCode ? $workshops->get($workshopCode) : null;
            if (! $workshop) {
                $skipped[] = $this->issue($row, 'La tecnología "'.$row['tech'].'" no está en el catálogo.');

                continue;
            }

            $entry = [
                'row' => $row['row'],
                'curp' => $curp,
                'name' => $name,
                'group' => $gradeName.$group->name,
                'workshop' => $workshop->name,
                'note' => '',
            ];

            if ($student) {
                $origin = $originYearId ? Enrollment::query()
                    ->with('classGroup.gradeLevel')
                    ->where('student_id', $student->id)
                    ->where('academic_year_id', $originYearId)
                    ->whereIn('status', [EnrollmentStatus::Active, EnrollmentStatus::Completed])
                    ->first() : null;
                $originGrade = (string) ($origin?->classGroup?->gradeLevel?->name ?? '');
                $originNumber = self::GRADE_NUMBER[$originGrade] ?? 0;
                $target = self::GRADE_NUMBER[$gradeName];

                if ($originNumber === $target - 1) {
                    $result = PromotionResult::PROMOTED;
                    $entry['note'] = 'Estaba en '.$originGrade.($origin->classGroup->name ?? '').' 2025-2026; aparece en la lista de '.$gradeName.', se promueve.';
                    $promoted[] = $entry;
                } elseif ($originNumber === $target) {
                    $result = PromotionResult::RETAINED;
                    $entry['note'] = 'Estaba en '.$originGrade.($origin->classGroup->name ?? '').' 2025-2026'
                        .($origin->promotion_result === PromotionResult::GRADUATED ? ' marcada como egresada' : '')
                        .'; aparece otra vez en '.$gradeName.', repite el grado.';
                    $retained[] = $entry;
                } else {
                    $skipped[] = $this->issue($row, $origin
                        ? 'Ya es alumno y estaba en '.$originGrade.' 2025-2026; no corresponde a '.$gradeName.'. Revisar a mano.'
                        : 'Ya es alumno, pero no tiene inscripción en 2025-2026. Revisar a mano.');

                    continue;
                }

                if ($dryRun) {
                    continue;
                }

                try {
                    DB::transaction(fn () => $this->moveExisting($student, $origin, $period, $year, $group, $workshop, $result, $gradeName));
                    $applied++;
                } catch (Throwable $exception) {
                    $errors[] = $this->issue($row, $exception->getMessage());
                }

                continue;
            }

            if (! $this->validCurp($curp)) {
                $skipped[] = $this->issue($row, 'La CURP del Excel no es válida; no se puede dar de alta.');

                continue;
            }
            $birth = $this->dateFromCurp($curp);
            $age = $birth ? Carbon::parse($birth)->age : null;
            if ($age === null || $age < 9 || $age > 20) {
                $skipped[] = $this->issue($row, 'La fecha de nacimiento de la CURP no corresponde a un alumno de secundaria.');

                continue;
            }
            $lookalike = $this->lookalike($curp, $name);
            if ($lookalike !== null) {
                $skipped[] = $this->issue($row, 'Posible duplicado de '.$lookalike.'; no se da de alta.');

                continue;
            }

            $entry['note'] = 'Alta tardía como ingreso nuevo.';
            $created[] = $entry;
            if ($dryRun) {
                continue;
            }

            try {
                DB::transaction(fn () => $this->createLate($row, $birth, $year, $group, $workshop, $gradeName));
                $applied++;
            } catch (Throwable $exception) {
                $errors[] = $this->issue($row, $exception->getMessage());
            }
        }

        return [
            'dry_run' => $dryRun,
            'grade' => $gradeName,
            'sheet' => $sheetName,
            'academic_year_label' => trim($year->year_start.'-'.$year->year_end),
            'rows' => count($rows),
            'already' => $already,
            'created' => $created,
            'promoted' => $promoted,
            'retained' => $retained,
            'applied' => $dryRun ? 0 : $applied,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    private function moveExisting(
        Student $student,
        Enrollment $origin,
        ?ReEnrollmentPeriod $period,
        AcademicYear $year,
        ClassGroup $group,
        Workshop $workshop,
        PromotionResult $result,
        string $gradeName,
    ): void {
        $origin->update([
            'is_approved' => $result === PromotionResult::PROMOTED,
            'status' => EnrollmentStatus::Completed,
            'promotion_result' => $result,
        ]);

        $destination = Enrollment::query()->firstOrNew([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
        ]);
        if ($destination->exists && $destination->status === EnrollmentStatus::Dropped) {
            throw new RuntimeException('La inscripción de 2026-2027 está en baja.');
        }
        $destination->fill([
            'class_group_id' => $group->id,
            'status' => EnrollmentStatus::Active,
            'is_new_admission' => false,
            'promotion_result' => $result,
        ]);
        $destination->save();

        if ($period) {
            ReEnrollmentApplication::query()
                ->where('re_enrollment_period_id', $period->id)
                ->where('student_id', $student->id)
                ->update([
                    'status' => ReEnrollmentValidationStatus::VALIDATED,
                    'passed_cycle' => $result === PromotionResult::PROMOTED,
                    'passed_cycle_source' => PassedCycleSource::MANUAL,
                    'target_class_group_id' => $group->id,
                ]);
        }

        $this->assignWorkshop($student->id, $year->id, $workshop, $gradeName);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function createLate(array $row, string $birth, AcademicYear $year, ClassGroup $group, Workshop $workshop, string $gradeName): void
    {
        $curp = $row['curp'];
        if (Profile::query()->where('national_id', $curp)->exists()) {
            throw new RuntimeException('Ya existe una persona con esa CURP.');
        }

        [$first, $lastName] = $this->splitName($row);
        $address = Address::query()->create([
            'street_type' => $row['street_type'] !== '' ? $row['street_type'] : self::NOT_SPECIFIED,
            'street_name' => $row['street'] !== '' ? $row['street'] : self::NOT_SPECIFIED,
            'house_number' => $row['exterior'] !== '' ? $row['exterior'] : ($row['interior'] !== '' ? $row['interior'] : self::NOT_SPECIFIED),
            'unit_number' => $row['exterior'] !== '' && $row['interior'] !== '' ? $row['interior'] : null,
            'neighborhood_type' => $row['settlement_type'] !== '' ? $row['settlement_type'] : self::NOT_SPECIFIED,
            'neighborhood_name' => $row['settlement'] !== '' ? $row['settlement'] : self::NOT_SPECIFIED,
            'postal_code' => '00000',
            'city' => $row['city'] !== '' ? $row['city'] : self::NOT_SPECIFIED,
            'state' => self::NOT_SPECIFIED,
        ]);

        $profile = Profile::query()->create([
            'national_id' => $curp,
            'first_name' => $first,
            'last_name' => $lastName,
            'birth_date' => $birth,
            'gender' => $this->genderFromCurp($curp) ?? 'O',
            'address_id' => $address->id,
        ]);

        $student = Student::query()->create(['profile_id' => $profile->id]);

        $this->attachTutor($student, $row, $address->id);

        Enrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'class_group_id' => $group->id,
            'status' => EnrollmentStatus::Active,
            'is_new_admission' => true,
            'admission_channel' => 'late',
            'placement_status' => 'placed',
            'placed_at' => now(),
        ]);

        $this->assignWorkshop($student->id, $year->id, $workshop, $gradeName);
    }

    /**
     * @param  array<string, string>  $row
     */
    private function attachTutor(Student $student, array $row, int $addressId): void
    {
        $tutorName = trim($row['g_name'].' '.$row['g_paterno'].' '.$row['g_materno']);
        $phone = $this->digits($row['phone']);
        if ($tutorName === '' && $phone === null) {
            return;
        }

        $curp = $row['g_curp'];
        if (! $this->validCurp($curp) || $curp === $row['curp'] || Profile::query()->where('national_id', $curp)->whereDoesntHave('guardian')->exists()) {
            $curp = SyncDirectoryDetailsService::unspecifiedCurp();
        }

        $email = strtolower(trim($row['email']));
        $email = filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        $kinship = $row['kinship'] !== '' ? $row['kinship'] : null;

        $guardianProfile = Profile::query()->firstOrCreate(
            ['national_id' => $curp],
            [
                'first_name' => $row['g_name'] !== '' ? $row['g_name'] : self::NOT_SPECIFIED,
                'last_name' => trim($row['g_paterno'].' '.$row['g_materno']) ?: self::NOT_SPECIFIED,
                'gender' => 'O',
                'address_id' => $addressId,
            ]
        );
        $guardianProfile->fill(array_filter(['phone_number' => $phone, 'email' => $email]));
        $guardianProfile->save();

        $guardian = Guardian::query()->firstOrCreate(
            ['profile_id' => $guardianProfile->id],
            ['Kinship' => $kinship]
        );
        $student->guardians()->syncWithoutDetaching([
            $guardian->id => ['relationship' => $kinship],
        ]);
    }

    private function assignWorkshop(int $studentId, int $yearId, Workshop $workshop, string $gradeName): void
    {
        $this->workshopWriter->upsert(
            studentId: $studentId,
            academicYearId: $yearId,
            workshopId: $workshop->id,
            source: WorkshopEnrollmentSource::Manual,
            status: WorkshopEnrollmentStatus::Assigned,
            notes: 'Directorio '.$gradeName.' 26-27',
        );
    }

    /**
     * @param  array<string, string>  $row
     * @return array{0: string, 1: string}
     */
    private function splitName(array $row): array
    {
        if ($row['first'] !== '' || $row['paterno'] !== '') {
            return [$row['first'], trim($row['paterno'].' '.$row['materno'])];
        }

        $tokens = preg_split('/\s+/', trim($row['full'])) ?: [];
        if (count($tokens) <= 2) {
            return [(string) ($tokens[1] ?? $tokens[0] ?? ''), (string) ($tokens[0] ?? '')];
        }

        return [implode(' ', array_slice($tokens, 2)), $tokens[0].' '.$tokens[1]];
    }

    /**
     * @return list<list<string>>
     */
    private function enrolledNames(int $yearId, int $gradeId): array
    {
        return Enrollment::query()
            ->where('academic_year_id', $yearId)
            ->where('status', EnrollmentStatus::Active)
            ->whereHas('classGroup', fn ($query) => $query->where('grade_level_id', $gradeId))
            ->with('student.profile')
            ->get()
            ->map(fn (Enrollment $enrollment) => $this->nameTokens(trim(($enrollment->student?->profile?->first_name ?? '').' '.($enrollment->student?->profile?->last_name ?? ''))))
            ->filter(fn (array $tokens) => count($tokens) >= 2)
            ->values()
            ->all();
    }

    /**
     * @param  list<list<string>>  $enrolled
     */
    private function nameIsEnrolled(string $name, array $enrolled): bool
    {
        $tokens = $this->nameTokens($name);
        if (count($tokens) < 2) {
            return false;
        }

        return in_array($tokens, $enrolled, true);
    }

    private function lookalike(string $curp, string $name): ?string
    {
        $tokens = $this->nameTokens($name);
        $candidates = Profile::query()
            ->whereHas('student')
            ->where('national_id', 'like', substr($curp, 0, 4).'%')
            ->get();
        foreach ($candidates as $profile) {
            $other = strtoupper(trim((string) $profile->national_id));
            if (abs(strlen($other) - strlen($curp)) <= 2 && levenshtein($other, $curp) <= 2
                && $this->nameTokens(trim($profile->first_name.' '.$profile->last_name)) === $tokens) {
                return trim($profile->first_name.' '.$profile->last_name).' ('.$other.')';
            }
        }

        return null;
    }

    public static function workshopCode(string $raw): ?string
    {
        $raw = trim($raw);
        if (isset(self::TECH_CODES[$raw])) {
            return self::TECH_CODES[$raw];
        }
        $key = strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtoupper($raw, 'UTF-8')) ?: $raw);

        return match (true) {
            str_contains($key, 'OFIMAT') => 'OFIMATICA',
            str_contains($key, 'INFORMAT') => 'INFORMATICA',
            str_contains($key, 'DISE') => 'DISENO',
            str_contains($key, 'CONFEC') || str_contains($key, 'VESTIDO') => 'CONFECCION',
            str_contains($key, 'MAQUINA') => 'MAQUINAS',
            default => null,
        };
    }

    private function groupLetter(string $raw): ?string
    {
        $raw = strtoupper(trim($raw));

        return preg_match('/([A-H])$/', $raw, $matches) === 1 ? $matches[1] : null;
    }

    /**
     * @return list<string>
     */
    private function nameTokens(string $value): array
    {
        $folded = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', mb_strtoupper($value, 'UTF-8')) ?: mb_strtoupper($value, 'UTF-8');
        $parts = preg_split('/[^A-Z0-9]+/', $folded, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parts = array_values(array_filter($parts, fn (string $token) => strlen($token) >= 3 && ! in_array($token, ['DEL', 'LAS', 'LOS'], true)));
        sort($parts);

        return $parts;
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

    private function validCurp(string $curp): bool
    {
        return preg_match('/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/', $curp) === 1;
    }

    private function dateFromCurp(string $curp): ?string
    {
        if (! preg_match('/^[A-Z]{4}(\d{2})(\d{2})(\d{2})[HM][A-Z]{5}([A-Z0-9])/', $curp, $matches)) {
            return null;
        }
        $year = (int) $matches[1] + (ctype_alpha($matches[4]) ? 2000 : 1900);

        return checkdate((int) $matches[2], (int) $matches[3], $year)
            ? sprintf('%04d-%02d-%02d', $year, (int) $matches[2], (int) $matches[3])
            : null;
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
     * @return array{row: string, curp: string, name: string, reason: string}
     */
    private function issue(array $row, string $reason): array
    {
        return [
            'row' => $row['row'],
            'curp' => $row['curp'],
            'name' => $row['student_name'],
            'reason' => $reason,
        ];
    }
}
