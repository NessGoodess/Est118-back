<?php

namespace App\Services\People;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Guardian credentials are issued only to primary tutors.
 * The secondary tutor on the card is an explicit backup (is_secondary), never a card holder.
 * Only minors enrolled in the active academic year count (skip stuck prior-cycle enrollments).
 */
class GuardianCredentialData
{
    /**
     * Eager-load graph for guardian credential payloads (active-year enrollments only).
     *
     * @return array<int|string, mixed>
     */
    public static function relations(?int $academicYearId = null): array
    {
        $yearId = $academicYearId ?? self::activeYearId();

        return [
            'profile.address',
            'students.profile:id,first_name,last_name,second_last_name',
            'students.guardians.profile:id,first_name,last_name,second_last_name,phone_number',
            'students.enrollments' => function ($eq) use ($yearId) {
                $eq->where('status', EnrollmentStatus::Active)
                    ->when($yearId, fn (Builder $q) => $q->where('academic_year_id', $yearId))
                    ->with(['classGroup.gradeLevel'])
                    ->latest('id');
            },
        ];
    }

    /**
     * Primary guardians with at least one minor enrolled in the active academic year.
     * Optional grade/group scope: tutor appears if any child is in that scope.
     */
    public function query(?int $gradeId = null, ?int $groupId = null): Builder
    {
        $yearId = self::activeYearId();

        return Guardian::query()
            ->whereHas('profile')
            ->whereHas(
                'students',
                function (Builder $studentQuery) use ($gradeId, $groupId, $yearId) {
                    $studentQuery->where('guardian_student.is_primary', true)
                        ->whereHas(
                            'enrollments',
                            function (Builder $eq) use ($gradeId, $groupId, $yearId) {
                                $eq->where('status', EnrollmentStatus::Active);
                                if ($yearId) {
                                    $eq->where('academic_year_id', $yearId);
                                }
                                if ($groupId) {
                                    $eq->where('class_group_id', $groupId);
                                } elseif ($gradeId) {
                                    $eq->whereHas(
                                        'classGroup',
                                        fn (Builder $cg) => $cg->where('grade_level_id', $gradeId)
                                    );
                                }
                            }
                        );
                }
            )
            ->with(self::relations($yearId));
    }

    public function folio(Guardian $guardian): string
    {
        return 'T'.str_pad((string) $guardian->id, 4, '0', STR_PAD_LEFT);
    }

    /**
     * @return Collection<int, Student>
     */
    public function activeStudents(Guardian $guardian): Collection
    {
        return $guardian->students
            ->filter(fn (Student $s) => $this->credentialEnrollment($s) !== null && $s->profile !== null)
            ->sortBy(fn (Student $s) => $this->gradeGroup($s).' '.$s->profile->fullLastName())
            ->values();
    }

    /**
     * Active minors where this guardian is the primary (credential owner).
     * When grade/group scope is set, matching children are listed first.
     *
     * @return Collection<int, Student>
     */
    public function primaryActiveStudents(
        Guardian $guardian,
        ?int $gradeId = null,
        ?int $groupId = null
    ): Collection {
        $students = $this->activeStudents($guardian)
            ->filter(fn (Student $s) => (bool) ($s->pivot?->is_primary ?? false))
            ->values();

        if ($groupId === null && $gradeId === null) {
            return $students;
        }

        $rank = function (Student $s) use ($gradeId, $groupId): int {
            $enrollment = $this->credentialEnrollment($s);
            $classGroup = $enrollment?->classGroup;
            if ($groupId !== null && (int) ($enrollment?->class_group_id ?? 0) === $groupId) {
                return 0;
            }
            if ($gradeId !== null && (int) ($classGroup?->grade_level_id ?? 0) === $gradeId) {
                return 1;
            }

            return 2;
        };

        return $students
            ->sortBy(fn (Student $s) => sprintf(
                '%d|%s|%s',
                $rank($s),
                $this->gradeGroup($s),
                $s->profile->fullLastName()
            ))
            ->values();
    }

    /**
     * @return array<int, array{student_id: int, grade_group: string, first_name: string, last_names: string}>
     */
    public function dependents(
        Guardian $guardian,
        ?int $gradeId = null,
        ?int $groupId = null
    ): array {
        return $this->primaryActiveStudents($guardian, $gradeId, $groupId)
            ->map(fn (Student $s) => [
                'student_id' => (int) $s->id,
                'grade_group' => $this->gradeGroup($s),
                'first_name' => (string) ($s->profile->first_name ?? ''),
                'last_names' => $s->profile->fullLastName(),
            ])
            ->all();
    }

    public function kinship(Guardian $guardian): string
    {
        $fromPivot = $this->primaryActiveStudents($guardian)
            ->map(fn (Student $s) => trim((string) ($s->pivot?->relationship ?? '')))
            ->first(fn (string $value) => $value !== '');

        return $fromPivot
            ?? $this->activeStudents($guardian)
                ->map(fn (Student $s) => trim((string) ($s->pivot?->relationship ?? '')))
                ->first(fn (string $value) => $value !== '')
            ?? trim((string) ($guardian->Kinship ?? ''));
    }

    /**
     * Explicit credential secondary for a primary holder's minors (is_secondary on pivot).
     *
     * @return array{name: string, phone: string, kinship: string}|null
     */
    public function secondGuardian(Guardian $guardian): ?array
    {
        foreach ($this->primaryActiveStudents($guardian) as $student) {
            $secondary = $student->guardians->first(
                fn (Guardian $g) => (int) $g->id !== (int) $guardian->id
                    && $g->profile !== null
                    && (bool) ($g->pivot?->is_secondary ?? false)
            );

            if ($secondary) {
                return [
                    'name' => $secondary->profile->fullName(),
                    'phone' => trim((string) ($secondary->profile->phone_number ?? '')),
                    'kinship' => trim((string) ($secondary->pivot?->relationship ?: ($secondary->Kinship ?? ''))),
                ];
            }
        }

        return null;
    }

    /**
     * Card text values for the guardian-only fields.
     * second_guardian is name / phone / kinship (one per line); the designer's
     * field label is the optional prefix drawn separately.
     *
     * @return array{kinship: string, dependent: string, dependents: string, second_guardian: string}
     */
    public function cardFields(
        Guardian $guardian,
        ?int $gradeId = null,
        ?int $groupId = null
    ): array {
        $lines = array_map(
            fn (array $d) => trim($d['grade_group'].' · '.$d['last_names'].', '.$d['first_name']),
            $this->dependents($guardian, $gradeId, $groupId)
        );
        $second = $this->secondGuardian($guardian);

        return [
            'kinship' => $this->kinship($guardian),
            'dependent' => $lines[0] ?? '',
            'dependents' => implode("\n", $lines),
            'second_guardian' => $second
                ? implode("\n", array_values(array_filter(
                    [$second['name'], $second['phone'], $second['kinship']],
                    fn (string $part) => $part !== ''
                )))
                : '',
        ];
    }

    private function gradeGroup(Student $student): string
    {
        $group = $this->credentialEnrollment($student)?->classGroup;

        return trim(($group?->gradeLevel?->name ?? '').' '.($group?->name ?? ''));
    }

    /**
     * Active enrollment in the current academic year (ignores stuck prior-cycle rows).
     */
    private function credentialEnrollment(Student $student): ?Enrollment
    {
        $yearId = self::activeYearId();

        if ($student->relationLoaded('enrollments')) {
            return $student->enrollments
                ->filter(function (Enrollment $e) use ($yearId) {
                    if ($e->status !== EnrollmentStatus::Active) {
                        return false;
                    }

                    return $yearId === null || (int) $e->academic_year_id === $yearId;
                })
                ->sortByDesc('id')
                ->first();
        }

        return $student->enrollments()
            ->where('status', EnrollmentStatus::Active)
            ->when($yearId, fn (Builder $q) => $q->where('academic_year_id', $yearId))
            ->with(['classGroup.gradeLevel'])
            ->latest('id')
            ->first();
    }

    private static function activeYearId(): ?int
    {
        $id = AcademicYear::query()->where('is_active', true)->value('id');

        return $id ? (int) $id : null;
    }
}
