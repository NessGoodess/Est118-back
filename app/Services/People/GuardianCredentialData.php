<?php

namespace App\Services\People;

use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * What a guardian credential shows: the guardian's minors with an active enrollment
 * and the other guardian authorized for them.
 */
class GuardianCredentialData
{
    /** Relations needed by dependents() and secondGuardian(). */
    public const RELATIONS = [
        'profile.address',
        'students.profile:id,first_name,last_name,second_last_name',
        'students.currentEnrollment.classGroup.gradeLevel',
        'students.guardians.profile:id,first_name,last_name,second_last_name',
    ];

    /** Guardians with at least one minor actively enrolled. */
    public function query(): Builder
    {
        return Guardian::query()
            ->whereHas('profile')
            ->whereHas('students.currentEnrollment')
            ->with(self::RELATIONS);
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
     * @return array<int, array{student_id: int, grade_group: string, first_name: string, last_names: string}>
     */
    public function dependents(Guardian $guardian): array
    {
        return $this->activeStudents($guardian)
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
        $fromPivot = $this->activeStudents($guardian)
            ->map(fn (Student $s) => trim((string) ($s->pivot?->relationship ?? '')))
            ->first(fn (string $value) => $value !== '');

        return $fromPivot ?? trim((string) ($guardian->Kinship ?? ''));
    }

    /**
     * The first other guardian of any of this guardian's active minors.
     *
     * @return array{name: string, kinship: string}|null
     */
    public function secondGuardian(Guardian $guardian): ?array
    {
        foreach ($this->activeStudents($guardian) as $student) {
            $other = $student->guardians->first(
                fn (Guardian $g) => $g->id !== $guardian->id && $g->profile !== null
            );
            if ($other) {
                return [
                    'name' => $other->profile->fullName(),
                    'kinship' => trim((string) ($other->pivot?->relationship ?: ($other->Kinship ?? ''))),
                ];
            }
        }

        return null;
    }

    /**
     * Card text values for the guardian-only fields.
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
                ? trim(($second['kinship'] !== '' ? $second['kinship'].': ' : '').$second['name'])
                : 'No registrado',
        ];
    }

    private function gradeGroup(Student $student): string
    {
        $group = $student->currentEnrollment?->classGroup;

        return trim(($group?->gradeLevel?->name ?? '').' '.($group?->name ?? ''));
    }
}
