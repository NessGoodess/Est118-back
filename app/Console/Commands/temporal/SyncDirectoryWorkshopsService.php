<?php

namespace App\Console\Commands\temporal;

use App\Enums\EnrollmentStatus;
use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Workshop;
use App\Models\WorkshopEnrollment;
use App\Services\WorkshopEnrollmentWriter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Solo el taller: a los inscritos activos de 2026-2027 les deja el taller que dice el directorio.
 */
class SyncDirectoryWorkshopsService
{
    public function __construct(
        private readonly SyncDirectoryDetailsService $reader,
        private readonly WorkshopEnrollmentWriter $workshopWriter,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function sync(string $path, int $academicYearId, string $gradeName, bool $dryRun = true): array
    {
        if (! is_file($path)) {
            throw new RuntimeException('No se encontró el Excel de directorio.');
        }

        $year = AcademicYear::query()->find($academicYearId);
        if (! $year || (string) $year->year_start !== '2026' || (string) $year->year_end !== '2027') {
            throw new RuntimeException('Este comando solo cambia talleres del ciclo 2026-2027.');
        }

        $grade = GradeLevel::query()->where('name', $gradeName)->first();
        if (! $grade) {
            throw new RuntimeException('No existe el grado '.$gradeName.' en el catálogo.');
        }

        $workshops = Workshop::query()->where('is_active', true)->get()->keyBy('code');
        [$sheetName, $rows] = $this->reader->readRows($path);
        $enrollments = Enrollment::query()
            ->where('academic_year_id', $year->id)
            ->where('status', EnrollmentStatus::Active)
            ->whereHas('classGroup', fn ($query) => $query->where('grade_level_id', $grade->id))
            ->with(['student.profile', 'classGroup'])
            ->get()
            ->filter(fn (Enrollment $enrollment) => $enrollment->student?->profile)
            ->values();
        $current = WorkshopEnrollment::query()
            ->where('academic_year_id', $year->id)
            ->whereIn('student_id', $enrollments->pluck('student_id'))
            ->with('workshop')
            ->get()
            ->keyBy('student_id');

        $changed = [];
        $added = [];
        $unchanged = 0;
        $skipped = [];
        $errors = [];
        $applied = 0;
        $used = [];

        foreach ($rows as $row) {
            [$enrollment, $reason] = $this->findEnrollment($row, $enrollments, $used);
            if (! $enrollment) {
                $skipped[] = $this->issue($row, $reason);

                continue;
            }
            $used[$enrollment->id] = true;

            if (trim($row['tech']) === '') {
                $skipped[] = $this->issue($row, 'El directorio no trae tecnología; se deja el taller de la base.');

                continue;
            }
            $code = EnrollDirectoryMissingService::workshopCode($row['tech']);
            $workshop = $code ? $workshops->get($code) : null;
            if (! $workshop) {
                $skipped[] = $this->issue($row, 'La tecnología "'.$row['tech'].'" no está en el catálogo; se deja el taller de la base.');

                continue;
            }

            $existing = $current->get($enrollment->student_id);
            $hadWorkshop = $existing && $existing->status === WorkshopEnrollmentStatus::Assigned && $existing->workshop;
            if ($hadWorkshop && (int) $existing->workshop_id === (int) $workshop->id) {
                $unchanged++;

                continue;
            }

            $profile = $enrollment->student->profile;
            $entry = [
                'student' => trim($profile->first_name.' '.$profile->last_name),
                'curp' => strtoupper(trim((string) $profile->national_id)),
                'group' => $gradeName.($enrollment->classGroup?->name ?? ''),
                'from' => $hadWorkshop ? (string) $existing->workshop->name : 'sin taller',
                'to' => (string) $workshop->name,
            ];
            if ($hadWorkshop) {
                $changed[] = $entry;
            } else {
                $added[] = $entry;
            }

            if ($dryRun) {
                continue;
            }

            try {
                DB::transaction(fn () => $this->workshopWriter->upsert(
                    studentId: $enrollment->student_id,
                    academicYearId: $year->id,
                    workshopId: $workshop->id,
                    source: WorkshopEnrollmentSource::Manual,
                    status: WorkshopEnrollmentStatus::Assigned,
                    notes: 'Directorio '.$gradeName.' 26-27',
                ));
                $applied++;
            } catch (Throwable $exception) {
                $errors[] = $this->issue($row, $exception->getMessage());
            }
        }

        $byGroup = fn (array $left, array $right) => [$left['group'], $left['student']] <=> [$right['group'], $right['student']];
        usort($changed, $byGroup);
        usort($added, $byGroup);

        return [
            'dry_run' => $dryRun,
            'grade' => $gradeName,
            'sheet' => $sheetName,
            'academic_year_label' => trim($year->year_start.'-'.$year->year_end),
            'rows' => count($rows),
            'matched' => count($used),
            'unchanged' => $unchanged,
            'changed' => $changed,
            'added' => $added,
            'applied' => $dryRun ? 0 : $applied,
            'skipped' => $skipped,
            'errors' => $errors,
        ];
    }

    /**
     * @param  array<string, string>  $row
     * @param  Collection<int, Enrollment>  $enrollments
     * @param  array<int, true>  $used
     * @return array{0: ?Enrollment, 1: string}
     */
    private function findEnrollment(array $row, Collection $enrollments, array $used): array
    {
        $free = $enrollments->reject(fn (Enrollment $enrollment) => isset($used[$enrollment->id]));
        if ($row['curp'] !== '') {
            $byCurp = $free->filter(fn (Enrollment $enrollment) => strtoupper(trim((string) $enrollment->student->profile->national_id)) === $row['curp']);
            if ($byCurp->count() === 1) {
                return [$byCurp->first(), ''];
            }
        }

        $tokens = $this->nameTokens($row['student_name']);
        if (count($tokens) >= 2) {
            $byName = $free->filter(fn (Enrollment $enrollment) => $this->nameTokens(
                $enrollment->student->profile->first_name.' '.$enrollment->student->profile->last_name
            ) === $tokens);
            if ($byName->count() === 1) {
                return [$byName->first(), ''];
            }
            if ($byName->count() > 1) {
                return [null, 'El nombre coincide con más de un inscrito; no se cambió el taller.'];
            }
        }

        return [null, 'No está inscrito en este grado de 2026-2027.'];
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
