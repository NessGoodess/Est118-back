<?php

namespace App\Services;

use App\Models\Guardian;
use App\Models\Student;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

class StudentSiblingService
{
    /**
     * @return list<array{id: int, name: string, curp: string, grade: string, group: string}>
     */
    public function listSiblings(Student $student): array
    {
        $student->loadMissing(['siblings.profile', 'siblings.currentEnrollment.classGroup.gradeLevel']);

        return $student->siblings
            ->map(fn (Student $sibling) => $this->studentMatchPayload($sibling))
            ->values()
            ->all();
    }

    /**
     * Bidirectional sibling link (same pattern as new intake).
     */
    public function linkSibling(Student $student, int $siblingStudentId): Student
    {
        if ($siblingStudentId === $student->id) {
            throw new RuntimeException('Un alumno no puede vincularse como hermano de sí mismo.');
        }

        $sibling = Student::query()->find($siblingStudentId);
        if (! $sibling) {
            throw new RuntimeException('El hermano indicado no existe.');
        }

        $student->siblings()->syncWithoutDetaching([$siblingStudentId]);
        $sibling->siblings()->syncWithoutDetaching([$student->id]);

        return app(StudentsService::class)->findForDetail($student->id);
    }

    /**
     * @param  list<int>  $siblingIds
     */
    public function linkSiblings(Student $student, array $siblingIds): void
    {
        $ids = collect($siblingIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0 && $id !== $student->id)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return;
        }

        $student->siblings()->syncWithoutDetaching($ids->all());
        foreach ($ids as $siblingId) {
            Student::query()->find($siblingId)?->siblings()->syncWithoutDetaching([$student->id]);
        }
    }

    public function unlinkSibling(Student $student, int $siblingStudentId): Student
    {
        $student->siblings()->detach($siblingStudentId);
        Student::query()->find($siblingStudentId)?->siblings()->detach($student->id);

        return app(StudentsService::class)->findForDetail($student->id);
    }

    /**
     * Guardians of linked siblings that are not yet linked to this student.
     *
     * @return list<array{id: int, name: string, curp: string|null, relationship: string|null, from_siblings: list<array{id: int, name: string}>}>
     */
    public function siblingGuardians(Student $student): array
    {
        $student->loadMissing([
            'siblings.profile',
            'siblings.guardians.profile',
            'guardians',
        ]);

        $alreadyLinked = $student->guardians->pluck('id')->all();
        $byGuardian = [];

        foreach ($student->siblings as $sibling) {
            foreach ($sibling->guardians as $guardian) {
                if (in_array($guardian->id, $alreadyLinked, true)) {
                    continue;
                }

                if (! isset($byGuardian[$guardian->id])) {
                    $profile = $guardian->profile;
                    $byGuardian[$guardian->id] = [
                        'id' => $guardian->id,
                        'name' => $profile?->fullName() ?? '',
                        'curp' => $profile?->national_id,
                        'relationship' => $guardian->pivot->relationship ?? null,
                        'from_siblings' => [],
                    ];
                }

                $byGuardian[$guardian->id]['from_siblings'][] = [
                    'id' => $sibling->id,
                    'name' => $sibling->profile?->fullName() ?? '',
                ];
            }
        }

        return array_values($byGuardian);
    }

    /**
     * Attach an existing guardian that belongs to a sibling.
     */
    public function attachGuardian(Student $student, int $guardianId, ?string $relationship = null): Student
    {
        $guardian = Guardian::query()->with('students')->find($guardianId);
        if (! $guardian) {
            throw new RuntimeException('El tutor indicado no existe.');
        }

        $siblingIds = $student->siblings()->pluck('students.id')->all();
        $guardianStudentIds = $guardian->students->pluck('id')->all();
        $sharesSibling = count(array_intersect($siblingIds, $guardianStudentIds)) > 0;

        if (! $sharesSibling) {
            throw new RuntimeException('Solo se pueden vincular tutores que ya estén ligados a un hermano de este alumno.');
        }

        $rel = trim((string) $relationship);
        $rel = $rel === '' ? ($guardian->students->firstWhere('id', '!=', $student->id)?->pivot?->relationship) : $rel;

        $student->guardians()->syncWithoutDetaching([
            $guardian->id => ['relationship' => $rel === '' ? null : $rel],
        ]);

        return app(StudentsService::class)->findForDetail($student->id);
    }

    /**
     * @return list<array{id: int, name: string, curp: string, grade: string, group: string}>
     */
    public function searchStudents(string $term, ?string $grade = null, ?string $group = null): array
    {
        $term = trim($term);
        $tokens = collect(preg_split('/\s+/u', mb_strtoupper($term), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            ->map(fn (string $token) => trim($token))
            ->filter(fn (string $token) => mb_strlen($token) >= 1)
            ->unique()
            ->values();

        if ($tokens->isEmpty() && ($grade === null || $grade === '') && ($group === null || $group === '')) {
            return [];
        }

        if ($tokens->isNotEmpty() && mb_strlen($term) < 2 && $tokens->count() === 1 && mb_strlen((string) $tokens->first()) < 2) {
            return [];
        }

        $query = Student::query()
            ->with(['profile', 'currentEnrollment.classGroup.gradeLevel']);

        if ($tokens->isNotEmpty()) {
            $query->whereHas('profile', function (Builder $q) use ($tokens) {
                foreach ($tokens as $token) {
                    $like = '%'.$token.'%';
                    $q->where(function (Builder $inner) use ($like) {
                        $inner->whereRaw('UPPER(first_name) LIKE ?', [$like])
                            ->orWhereRaw('UPPER(last_name) LIKE ?', [$like])
                            ->orWhereRaw('UPPER(COALESCE(second_last_name, "")) LIKE ?', [$like])
                            ->orWhereRaw('UPPER(COALESCE(national_id, "")) LIKE ?', [$like]);
                    });
                }
            });
        }

        if ($grade !== null && $grade !== '') {
            $query->whereHas('currentEnrollment.classGroup.gradeLevel', function (Builder $q) use ($grade) {
                $q->where('name', $grade);
            });
        }

        if ($group !== null && $group !== '') {
            $query->whereHas('currentEnrollment.classGroup', function (Builder $q) use ($group) {
                $q->where('name', $group);
            });
        }

        return $query
            ->limit(12)
            ->get()
            ->map(fn (Student $student) => $this->studentMatchPayload($student))
            ->values()
            ->all();
    }

    /**
     * @return array{id: int, name: string, curp: string, grade: string, group: string}
     */
    private function studentMatchPayload(Student $student): array
    {
        $enrollment = $student->relationLoaded('currentEnrollment')
            ? $student->currentEnrollment
            : $student->currentEnrollment()->with('classGroup.gradeLevel')->first();
        $profile = $student->profile;

        return [
            'id' => $student->id,
            'name' => $profile?->fullName() ?? '',
            'curp' => strtoupper(trim((string) ($profile?->national_id ?? ''))),
            'grade' => (string) ($enrollment?->classGroup?->gradeLevel?->name ?? ''),
            'group' => (string) ($enrollment?->classGroup?->name ?? ''),
        ];
    }
}
