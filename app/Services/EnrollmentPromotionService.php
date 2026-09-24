<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Enums\PromotionResult;
use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\WorkshopEnrollment;
use App\Models\WorkshopOffering;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EnrollmentPromotionService
{
    public function __construct(
        private readonly WorkshopEnrollmentWriter $workshopWriter
    ) {}
    /**
     * Promote one academic year into the next one.
     *
     * Rules:
     * - Failed students remain in the same grade and same group letter.
     * - Approved students in 1st and 2nd move to next grade with same group letter.
     * - Approved students in 3rd are graduated (no new enrollment).
     */
    /**
     * @param  (callable(Enrollment): EnrollmentStatus)|null  $destinationStatusFor
     */
    public function promote(
        int $fromAcademicYearId,
        int $toAcademicYearId,
        bool $dryRun = false,
        ?callable $destinationStatusFor = null
    ): array {
        if ($fromAcademicYearId === $toAcademicYearId) {
            throw new RuntimeException('El ciclo origen y destino no pueden ser el mismo.');
        }

        $fromYear = AcademicYear::findOrFail($fromAcademicYearId);
        $toYear = AcademicYear::findOrFail($toAcademicYearId);

        $enrollments = Enrollment::query()
            ->with(['classGroup.gradeLevel'])
            ->where('academic_year_id', $fromYear->id)
            ->where('status', EnrollmentStatus::Active->value)
            ->get();

        $decided = $enrollments->whereNotNull('is_approved')->values();
        $skippedWithoutDecision = $enrollments->whereNull('is_approved')->count();

        $this->ensureDestinationGroups($fromYear->id, $toYear->id);

        $summary = [
            'from_academic_year_id' => $fromYear->id,
            'to_academic_year_id' => $toYear->id,
            'processed' => 0,
            'promoted' => 0,
            'retained' => 0,
            'graduated' => 0,
            'skipped_without_decision' => $skippedWithoutDecision,
            'workshops_inherited' => 0,
            'workshops_skipped_manual' => 0,
            'workshops_missing' => 0,
            'workshop_missing_student_ids' => [],
            'workshops_over_capacity' => [],
            'errors' => [],
        ];

        $fromWorkshops = WorkshopEnrollment::query()
            ->where('academic_year_id', $fromYear->id)
            ->where('status', WorkshopEnrollmentStatus::Assigned->value)
            ->get()
            ->keyBy('student_id');

        $runner = function () use ($decided, $toYear, $fromWorkshops, $destinationStatusFor, &$summary): void {
            foreach ($decided as $enrollment) {
                $summary['processed']++;

                try {
                    $decision = $this->resolveDecision($enrollment->classGroup->gradeLevel->name, (bool) $enrollment->is_approved);

                    if ($decision['result'] === PromotionResult::GRADUATED) {
                        $enrollment->update([
                            'status' => EnrollmentStatus::Completed,
                            'promotion_result' => PromotionResult::GRADUATED,
                        ]);
                        $summary['graduated']++;
                        continue;
                    }

                    $targetGroup = $this->findTargetGroup(
                        $toYear->id,
                        $decision['target_grade'],
                        $enrollment->classGroup->name
                    );

                    $nextEnrollment = Enrollment::firstOrNew([
                        'student_id' => $enrollment->student_id,
                        'academic_year_id' => $toYear->id,
                    ]);

                    if ($nextEnrollment->exists && $nextEnrollment->status === EnrollmentStatus::Dropped) {
                        continue;
                    }

                    $destStatus = EnrollmentStatus::Active;
                    if ($destinationStatusFor) {
                        $resolved = $destinationStatusFor($enrollment);
                        $destStatus = $resolved instanceof EnrollmentStatus
                            ? $resolved
                            : EnrollmentStatus::from((string) $resolved);
                    }

                    if ($nextEnrollment->exists && $nextEnrollment->status === EnrollmentStatus::Active) {
                        $destStatus = EnrollmentStatus::Active;
                    }

                    $nextEnrollment->fill([
                        'class_group_id' => $targetGroup->id,
                        'status' => $destStatus,
                        'is_new_admission' => false,
                        'promotion_result' => $decision['result'],
                    ]);
                    $nextEnrollment->save();

                    $fromWorkshop = $fromWorkshops->get($enrollment->student_id);
                    if ($fromWorkshop) {
                        $existingDest = WorkshopEnrollment::query()
                            ->where('student_id', $enrollment->student_id)
                            ->where('academic_year_id', $toYear->id)
                            ->first();

                        if ($existingDest?->source === WorkshopEnrollmentSource::Manual) {
                            $summary['workshops_skipped_manual']++;
                        } else {
                            $this->workshopWriter->upsert(
                                studentId: $enrollment->student_id,
                                academicYearId: $toYear->id,
                                workshopId: (int) $fromWorkshop->workshop_id,
                                source: WorkshopEnrollmentSource::Inherited,
                                status: WorkshopEnrollmentStatus::Assigned,
                                notes: $existingDest?->notes,
                                assignedBy: null,
                                protectManual: true,
                            );
                            $summary['workshops_inherited']++;
                        }
                    } else {
                        $summary['workshops_missing']++;
                        if (count($summary['workshop_missing_student_ids']) < 100) {
                            $summary['workshop_missing_student_ids'][] = $enrollment->student_id;
                        }
                    }

                    $enrollment->update([
                        'status' => EnrollmentStatus::Completed,
                        'promotion_result' => $decision['result'],
                    ]);

                    if ($decision['result'] === PromotionResult::PROMOTED) {
                        $summary['promoted']++;
                    } else {
                        $summary['retained']++;
                    }
                } catch (\Throwable $e) {
                    $summary['errors'][] = [
                        'enrollment_id' => $enrollment->id,
                        'student_id' => $enrollment->student_id,
                        'message' => $e->getMessage(),
                    ];
                }
            }

            $summary['workshops_over_capacity'] = $this->overCapacityRows($toYear->id);
        };

        if ($dryRun) {
            try {
                DB::transaction(function () use ($runner): void {
                    $runner();
                    throw new RuntimeException('__DRY_RUN_ROLLBACK__');
                });
            } catch (RuntimeException $e) {
                if ($e->getMessage() !== '__DRY_RUN_ROLLBACK__') {
                    throw $e;
                }
            }
        } else {
            DB::transaction($runner);
        }

        return $summary;
    }

    /**
     * @return array{result: PromotionResult, target_grade: string|null}
     */
    private function resolveDecision(string $gradeName, bool $isApproved): array
    {
        if (! in_array($gradeName, ['1°', '2°', '3°'], true)) {
            throw new RuntimeException("Grado no soportado para promoción: {$gradeName}");
        }

        if (! $isApproved) {
            return [
                'result' => PromotionResult::RETAINED,
                'target_grade' => $gradeName,
            ];
        }

        if ($gradeName === '3°') {
            return [
                'result' => PromotionResult::GRADUATED,
                'target_grade' => null,
            ];
        }

        return [
            'result' => PromotionResult::PROMOTED,
            'target_grade' => $gradeName === '1°' ? '2°' : '3°',
        ];
    }

    /**
     * @return list<array{workshop_id:int, workshop_name:?string, occupied:int, capacity:int}>
     */
    private function overCapacityRows(int $academicYearId): array
    {
        $offerings = WorkshopOffering::query()
            ->with('workshop:id,name')
            ->where('academic_year_id', $academicYearId)
            ->whereNotNull('capacity')
            ->get();

        if ($offerings->isEmpty()) {
            return [];
        }

        $occupied = WorkshopEnrollment::query()
            ->where('academic_year_id', $academicYearId)
            ->where('status', WorkshopEnrollmentStatus::Assigned->value)
            ->selectRaw('workshop_id, COUNT(*) as occupied')
            ->groupBy('workshop_id')
            ->pluck('occupied', 'workshop_id');

        $rows = [];
        foreach ($offerings as $offering) {
            $taken = (int) ($occupied[$offering->workshop_id] ?? 0);
            if ($taken > (int) $offering->capacity) {
                $rows[] = [
                    'workshop_id' => $offering->workshop_id,
                    'workshop_name' => $offering->workshop?->name,
                    'occupied' => $taken,
                    'capacity' => (int) $offering->capacity,
                ];
            }
        }

        return $rows;
    }

    /**
     * Create missing destination groups, including 3°, so retained third-graders
     * can repeat and approved second-graders have a 3° seat.
     */
    private function ensureDestinationGroups(int $fromAcademicYearId, int $toAcademicYearId): void
    {
        $sourceGroups = ClassGroup::query()
            ->with('gradeLevel:id,name')
            ->where('academic_year_id', $fromAcademicYearId)
            ->get();

        foreach ($sourceGroups as $source) {
            $gradeName = $source->gradeLevel?->name;
            if (! is_string($gradeName) || $gradeName === '') {
                continue;
            }

            $this->ensureGroup($toAcademicYearId, $gradeName, $source->name);

            if ($gradeName === '1°') {
                $this->ensureGroup($toAcademicYearId, '2°', $source->name);
            } elseif ($gradeName === '2°') {
                $this->ensureGroup($toAcademicYearId, '3°', $source->name);
            }
        }
    }

    private function ensureGroup(int $academicYearId, string $gradeName, string $groupName): ClassGroup
    {
        $existing = ClassGroup::query()
            ->where('academic_year_id', $academicYearId)
            ->where('name', $groupName)
            ->whereHas('gradeLevel', function ($query) use ($gradeName): void {
                $query->where('name', $gradeName);
            })
            ->first();

        if ($existing) {
            return $existing;
        }

        $grade = GradeLevel::query()->where('name', $gradeName)->first();
        if (! $grade) {
            throw new RuntimeException("No existe el grado {$gradeName} para crear grupos destino.");
        }

        return ClassGroup::create([
            'academic_year_id' => $academicYearId,
            'grade_level_id' => $grade->id,
            'name' => $groupName,
        ]);
    }

    private function findTargetGroup(int $academicYearId, string $gradeName, string $groupName): ClassGroup
    {
        return $this->ensureGroup($academicYearId, $gradeName, $groupName);
    }
}
