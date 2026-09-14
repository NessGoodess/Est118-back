<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Workshop;
use App\Models\WorkshopEnrollment;
use App\Models\WorkshopOffering;
use App\Support\WorkshopLetterHint;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AssignBulkGhWorkshopsService
{
    public function __construct(
        private readonly WorkshopEnrollmentWriter $writer
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(int $academicYearId, bool $dryRun = true, bool $force = false): array
    {
        $byCode = Workshop::query()->where('is_active', true)->get()->keyBy('code');
        $informatics = $byCode->get('INFORMATICA');
        $design = $byCode->get('DISENO');
        if (! $informatics || ! $design) {
            throw new RuntimeException('Faltan los talleres Informática o Diseño Industrial en el catálogo.');
        }

        $offerings = WorkshopOffering::query()
            ->where('academic_year_id', $academicYearId)
            ->get()
            ->keyBy('workshop_id');

        $enrollments = Enrollment::query()
            ->with(['student.profile', 'classGroup'])
            ->where('academic_year_id', $academicYearId)
            ->where('status', EnrollmentStatus::Active->value)
            ->whereHas('classGroup', fn ($q) => $q->whereIn('name', ['G', 'H']))
            ->get();

        $existing = WorkshopEnrollment::query()
            ->where('academic_year_id', $academicYearId)
            ->get()
            ->keyBy('student_id');

        $occupied = WorkshopEnrollment::query()
            ->where('academic_year_id', $academicYearId)
            ->where('status', WorkshopEnrollmentStatus::Assigned->value)
            ->selectRaw('workshop_id, COUNT(*) as occupied')
            ->groupBy('workshop_id')
            ->pluck('occupied', 'workshop_id')
            ->all();

        $assignments = [];
        foreach ($enrollments as $enrollment) {
            $letter = (string) ($enrollment->classGroup?->name ?? '');
            $expected = WorkshopLetterHint::expectedCode($letter);
            $workshop = $expected === 'INFORMATICA' ? $informatics : ($expected === 'DISENO' ? $design : null);
            $row = $existing->get($enrollment->student_id);
            $flags = [];
            $willApply = true;

            if (! $workshop) {
                $flags[] = 'unknown_letter';
                $willApply = false;
            } elseif ($row?->isProtectedFromBatch()) {
                $flags[] = 'protected';
                $willApply = false;
            } else {
                $offering = $offerings->get($workshop->id);
                $taken = (int) ($occupied[$workshop->id] ?? 0);
                if ($offering && $offering->capacity !== null && $taken >= (int) $offering->capacity && ! $force) {
                    $flags[] = 'over_capacity';
                    $willApply = false;
                } elseif ($offering && $offering->capacity !== null && $taken >= (int) $offering->capacity && $force) {
                    $flags[] = 'over_capacity';
                }
                if ($willApply) {
                    $occupied[$workshop->id] = $taken + 1;
                }
            }

            $assignments[] = [
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'student_name' => trim(
                    ($enrollment->student?->profile?->first_name ?? '').' '.
                    ($enrollment->student?->profile?->last_name ?? '')
                ),
                'class_group_name' => $letter,
                'workshop_id' => $workshop?->id,
                'workshop_name' => $workshop?->name,
                'source' => WorkshopEnrollmentSource::BulkGh->value,
                'flags' => $flags,
                'will_apply' => $willApply && $workshop !== null,
            ];
        }

        if (! $dryRun) {
            DB::transaction(function () use ($assignments, $academicYearId): void {
                foreach ($assignments as $assignment) {
                    if (! ($assignment['will_apply'] ?? false)) {
                        continue;
                    }
                    $this->writer->upsert(
                        studentId: $assignment['student_id'],
                        academicYearId: $academicYearId,
                        workshopId: (int) $assignment['workshop_id'],
                        source: WorkshopEnrollmentSource::BulkGh,
                        status: WorkshopEnrollmentStatus::Assigned,
                        notes: 'Asignación masiva G/H (patrón Excel).',
                        assignedBy: Auth::id(),
                        protectManual: true,
                    );
                }
            });
        }

        return [
            'dry_run' => $dryRun,
            'academic_year_id' => $academicYearId,
            'assignments' => $assignments,
            'summary' => [
                'total_candidates' => count($assignments),
                'assigned' => count(array_filter($assignments, fn ($a) => $a['will_apply'])),
                'protected' => count(array_filter($assignments, fn ($a) => in_array('protected', $a['flags'], true))),
                'over_capacity' => count(array_filter($assignments, fn ($a) => in_array('over_capacity', $a['flags'], true))),
            ],
        ];
    }
}
