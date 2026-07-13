<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Enums\PromotionResult;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class EnrollmentPromotionService
{
    /**
     * Promote one academic year into the next one.
     *
     * Rules:
     * - Failed students remain in the same grade and same group letter.
     * - Approved students in 1st and 2nd move to next grade with same group letter.
     * - Approved students in 3rd are graduated (no new enrollment).
     */
    public function promote(int $fromAcademicYearId, int $toAcademicYearId, bool $dryRun = false): array
    {
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

        $pendingDecisions = $enrollments->whereNull('is_approved')->count();
        if ($pendingDecisions > 0) {
            throw new RuntimeException(
                "No se puede ejecutar la promoción: hay {$pendingDecisions} inscripciones activas sin decisión (is_approved)."
            );
        }

        $summary = [
            'from_academic_year_id' => $fromYear->id,
            'to_academic_year_id' => $toYear->id,
            'processed' => 0,
            'promoted' => 0,
            'retained' => 0,
            'graduated' => 0,
            'errors' => [],
        ];

        $runner = function () use ($enrollments, $toYear, &$summary): void {
            foreach ($enrollments as $enrollment) {
                $summary['processed']++;

                try {
                    $decision = $this->resolveDecision($enrollment->classGroup->gradeLevel->name, $enrollment->is_approved);

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

                    $nextEnrollment->fill([
                        'class_group_id' => $targetGroup->id,
                        'status' => EnrollmentStatus::Active,
                        'is_new_admission' => false,
                        'promotion_result' => $decision['result'],
                    ]);
                    $nextEnrollment->save();

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

    private function findTargetGroup(int $academicYearId, string $gradeName, string $groupName): ClassGroup
    {
        return ClassGroup::query()
            ->where('academic_year_id', $academicYearId)
            ->where('name', $groupName)
            ->whereHas('gradeLevel', function ($query) use ($gradeName): void {
                $query->where('name', $gradeName);
            })
            ->firstOr(function () use ($academicYearId, $gradeName, $groupName) {
                throw new RuntimeException(
                    "No existe grupo destino {$gradeName}{$groupName} en ciclo {$academicYearId}."
                );
            });
    }
}
