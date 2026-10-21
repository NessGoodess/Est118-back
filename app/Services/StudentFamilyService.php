<?php

namespace App\Services;

use App\Exceptions\GuardianCurpConflictException;
use App\Models\Guardian;
use App\Models\Profile;
use App\Models\Student;
use App\Support\Curp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StudentFamilyService
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
        if (mb_strlen($term) < 2 && $grade === null && $group === null) {
            return [];
        }

        $query = Student::query()
            ->with(['profile', 'currentEnrollment.classGroup.gradeLevel']);

        if (mb_strlen($term) >= 2) {
            $like = '%'.$term.'%';
            $query->whereHas('profile', function (Builder $q) use ($like) {
                $q->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('second_last_name', 'like', $like)
                    ->orWhere('national_id', 'like', $like);
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
     * @param  array{first_name: string, last_name: string, national_id?: string|null, relationship?: string|null, email?: string|null, phone?: string|null}  $data
     */
    public function updateGuardianOrConflict(Student $student, Guardian $guardian, array $data): Student
    {
        $linked = $student->guardians()->whereKey($guardian->id)->exists();
        if (! $linked) {
            throw new RuntimeException('El tutor no está asociado a este alumno.');
        }

        $guardian->loadMissing('profile');
        $profile = $guardian->profile;
        if (! $profile) {
            throw new RuntimeException('El tutor no tiene perfil asociado.');
        }

        $curp = Curp::normalize($data['national_id'] ?? null);
        $derived = $curp === '' ? null : Curp::derive($curp);
        if ($curp !== '' && $derived === null) {
            throw new RuntimeException('La CURP del tutor no es válida.');
        }

        if ($curp !== '') {
            $other = Profile::query()
                ->where('national_id', $curp)
                ->where('id', '!=', $profile->id)
                ->with(['guardian.students.profile', 'teacher', 'staff', 'student'])
                ->first();

            if ($other) {
                if ($other->student) {
                    throw new RuntimeException('Esa CURP pertenece a un alumno; no se puede asignar a un tutor.');
                }

                if (! $other->guardian) {
                    throw new RuntimeException('Esa CURP ya está registrada, pero no corresponde a un tutor.');
                }

                throw new GuardianCurpConflictException(
                    $this->canonicalGuardianPayload($other)
                );
            }
        }

        $profile->fill([
            'first_name' => trim($data['first_name']),
            'last_name' => trim($data['last_name']),
            'second_last_name' => null,
            'email' => $data['email'] ?? null,
            'phone_number' => $data['phone'] ?? null,
            'national_id' => $curp === '' ? null : $curp,
            'birth_date' => $derived['birth_date'] ?? null,
            'gender' => $derived['gender'] ?? null,
        ]);
        $profile->save();

        $relationship = trim((string) ($data['relationship'] ?? ''));
        $student->guardians()->updateExistingPivot($guardian->id, [
            'relationship' => $relationship === '' ? null : $relationship,
        ]);

        return app(StudentsService::class)->findForDetail($student->id);
    }

    /**
     * Replace draft guardian on this student with the canonical one that owns the CURP.
     */
    public function mergeGuardianByCurp(
        Student $student,
        int $draftGuardianId,
        int $canonicalGuardianId,
        ?string $relationship = null
    ): Student {
        return DB::transaction(function () use ($student, $draftGuardianId, $canonicalGuardianId, $relationship) {
            if ($draftGuardianId === $canonicalGuardianId) {
                throw new RuntimeException('El tutor borrador y el canónico deben ser distintos.');
            }

            $draft = Guardian::query()->with('profile')->find($draftGuardianId);
            $canonical = Guardian::query()->with('profile')->find($canonicalGuardianId);

            if (! $draft || ! $canonical) {
                throw new RuntimeException('Tutor no encontrado.');
            }

            if (! $student->guardians()->whereKey($draft->id)->exists()) {
                throw new RuntimeException('El tutor borrador no está asociado a este alumno.');
            }

            $rel = trim((string) $relationship);
            if ($rel === '') {
                $rel = (string) ($student->guardians()->whereKey($draft->id)->first()?->pivot?->relationship ?? '');
            }

            $student->guardians()->syncWithoutDetaching([
                $canonical->id => ['relationship' => $rel === '' ? null : $rel],
            ]);
            $student->guardians()->detach($draft->id);

            $draftStillUsed = $draft->students()->exists();
            if (! $draftStillUsed) {
                $draftProfile = $draft->profile;
                $draft->delete();
                if ($draftProfile && $draftProfile->national_id === null) {
                    $hasOtherRoles = $draftProfile->teacher()->exists()
                        || $draftProfile->staff()->exists()
                        || $draftProfile->student()->exists();
                    if (! $hasOtherRoles) {
                        $draftProfile->delete();
                    }
                }
            }

            return app(StudentsService::class)->findForDetail($student->id);
        });
    }

    /**
     * Lookup a profile by CURP for pre-enrollment review (auth only).
     *
     * @return array{found: bool, profile?: array<string, mixed>}
     */
    public function lookupPersonByCurp(?string $curp): array
    {
        $normalized = Curp::normalize($curp);
        if ($normalized === '') {
            return ['found' => false, 'reason' => 'missing_curp'];
        }

        $profile = Profile::query()
            ->where('national_id', $normalized)
            ->with(['guardian.students.profile', 'teacher', 'staff', 'student'])
            ->first();

        if (! $profile) {
            return ['found' => false, 'reason' => 'not_found'];
        }

        return [
            'found' => true,
            'profile' => $this->canonicalGuardianPayload($profile),
        ];
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

    /**
     * @return array<string, mixed>
     */
    private function canonicalGuardianPayload(Profile $profile): array
    {
        $roles = [];
        if ($profile->guardian) {
            $roles[] = 'guardian';
        }
        if ($profile->teacher) {
            $roles[] = 'teacher';
        }
        if ($profile->staff) {
            $roles[] = 'staff';
        }
        if ($profile->student) {
            $roles[] = 'student';
        }

        $students = [];
        if ($profile->guardian) {
            $profile->guardian->loadMissing('students.profile');
            foreach ($profile->guardian->students as $linked) {
                $students[] = [
                    'id' => $linked->id,
                    'name' => $linked->profile?->fullName() ?? '',
                ];
            }
        }

        return [
            'profile_id' => $profile->id,
            'guardian_id' => $profile->guardian?->id,
            'name' => $profile->fullName(),
            'curp' => $profile->national_id,
            'phone' => $profile->phone_number,
            'email' => $profile->email,
            'roles' => $roles,
            'students' => $students,
        ];
    }
}
