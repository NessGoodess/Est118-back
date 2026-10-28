<?php

namespace App\Services\People;

use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Guardian credentials are issued only to primary tutors.
 * The secondary tutor on the card is an explicit backup (is_secondary), never a card holder.
 */
class GuardianCredentialData
{
    /** Relations needed by dependents() and secondGuardian(). */
    public const RELATIONS = [
        'profile.address',
        'students.profile:id,first_name,last_name,second_last_name',
        'students.currentEnrollment.classGroup.gradeLevel',
        'students.guardians.profile:id,first_name,last_name,second_last_name,phone_number',
    ];

    /**
     * Primary guardians with at least one minor actively enrolled (credential holders only).
     * Optional grade/group scope: tutor appears if any child is in that scope.
     */
    public function query(?int $gradeId = null, ?int $groupId = null): Builder
    {
        $q = Guardian::query()
            ->whereHas('profile')
            ->whereHas(
                'students',
                function (Builder $studentQuery) use ($gradeId, $groupId) {
                    $studentQuery->where('guardian_student.is_primary', true);

                    if ($groupId) {
                        $studentQuery->whereHas(
                            'currentEnrollment',
                            fn (Builder $eq) => $eq->where('class_group_id', $groupId)
                        );
                    } elseif ($gradeId) {
                        $studentQuery->whereHas(
                            'currentEnrollment.classGroup',
                            fn (Builder $cg) => $cg->where('grade_level_id', $gradeId)
                        );
                    } else {
                        $studentQuery->whereHas('currentEnrollment');
                    }
                }
            )
            ->with(self::RELATIONS);

        return $q;
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
            ->filter(fn (Student $s) => $s->currentEnrollment !== null && $s->profile !== null)
            ->sortBy(fn (Student $s) => $this->gradeGroup($s).' '.$s->profile->fullLastName())
            ->values();
    }

    /**
     * Active minors where this guardian is the primary (credential owner).
     *
     * @return Collection<int, Student>
     */
    public function primaryActiveStudents(Guardian $guardian): Collection
    {
        return $this->activeStudents($guardian)
            ->filter(fn (Student $s) => (bool) ($s->pivot?->is_primary ?? false))
            ->values();
    }

    /**
     * @return array<int, array{student_id: int, grade_group: string, first_name: string, last_names: string}>
     */
    public function dependents(Guardian $guardian): array
    {
        return $this->primaryActiveStudents($guardian)
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
    public function cardFields(Guardian $guardian): array
    {
        $lines = array_map(
            fn (array $d) => trim($d['grade_group'].' · '.$d['last_names'].', '.$d['first_name']),
            $this->dependents($guardian)
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
                : 'No registrado',
        ];
    }

    private function gradeGroup(Student $student): string
    {
        $group = $student->currentEnrollment?->classGroup;

        return trim(($group?->gradeLevel?->name ?? '').' '.($group?->name ?? ''));
    }
}
