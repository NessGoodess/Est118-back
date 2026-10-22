<?php

namespace App\Services;

use App\Exceptions\GuardianCurpConflictException;
use App\Models\Guardian;
use App\Models\Profile;
use App\Models\Student;
use App\Support\Curp;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class GuardianCurpMergeService
{
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

                $guardian->loadMissing('students.profile');
                throw new GuardianCurpConflictException(
                    $this->canonicalGuardianPayload($other),
                    $this->canonicalGuardianPayload($profile),
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
     * @param  array<string, mixed>  $pending
     */
    public function assertCurpFreeOrConflict(string $curp, array $pending): void
    {
        $normalized = Curp::normalize($curp);
        if ($normalized === '') {
            return;
        }

        $other = Profile::query()
            ->where('national_id', $normalized)
            ->with(['guardian.students.profile', 'teacher', 'staff', 'student'])
            ->first();

        if (! $other) {
            return;
        }

        if ($other->student) {
            throw new RuntimeException('Esa CURP pertenece a un alumno; no se puede asignar a un tutor.');
        }

        if (! $other->guardian) {
            throw new RuntimeException('Esa CURP ya está registrada, pero no corresponde a un tutor.');
        }

        throw new GuardianCurpConflictException(
            $this->canonicalGuardianPayload($other),
            null,
            [
                'first_name' => $pending['first_name'] ?? null,
                'last_name' => $pending['last_name'] ?? null,
                'second_last_name' => $pending['second_last_name'] ?? null,
                'phone' => $pending['phone'] ?? null,
                'email' => $pending['email'] ?? null,
                'relationship' => $pending['relationship'] ?? null,
                'curp' => $normalized,
                'name' => trim(implode(' ', array_filter([
                    $pending['first_name'] ?? null,
                    $pending['last_name'] ?? null,
                    $pending['second_last_name'] ?? null,
                ]))),
            ],
        );
    }

    /**
     * Merge two guardians that share a CURP, choosing a survivor and field sources.
     *
     * @param  array{
     *   draft_guardian_id?: int|null,
     *   canonical_guardian_id: int,
     *   survivor?: 'draft'|'canonical',
     *   field_sources?: array{name?: string, phone?: string, email?: string, address?: string},
     *   relationship?: string|null,
     *   pending?: array<string, mixed>|null
     * }  $options
     */
    public function mergeGuardianByCurp(Student $student, array $options): Student
    {
        return DB::transaction(function () use ($student, $options) {
            $canonicalId = (int) $options['canonical_guardian_id'];
            $draftId = isset($options['draft_guardian_id']) ? (int) $options['draft_guardian_id'] : null;
            $survivorSide = ($options['survivor'] ?? 'canonical') === 'draft' ? 'draft' : 'canonical';
            $sources = $options['field_sources'] ?? [];
            $pending = is_array($options['pending'] ?? null) ? $options['pending'] : null;
            $relationship = trim((string) ($options['relationship'] ?? ''));

            $canonical = Guardian::query()->with(['profile.address', 'students'])->find($canonicalId);
            if (! $canonical || ! $canonical->profile) {
                throw new RuntimeException('El tutor existente no se encontró.');
            }

            $draft = null;
            if ($draftId) {
                $draft = Guardian::query()->with(['profile.address', 'students'])->find($draftId);
                if (! $draft) {
                    throw new RuntimeException('El tutor borrador no se encontró.');
                }
                if ($draft->id === $canonical->id) {
                    throw new RuntimeException('El tutor borrador y el canónico deben ser distintos.');
                }
            }

            // Add-only conflict: attach canonical and optionally overwrite fields from pending.
            if (! $draft) {
                if ($pending) {
                    $this->applyPendingFieldsToProfile($canonical->profile, $pending, $sources);
                }
                $rel = $relationship !== ''
                    ? $relationship
                    : (string) ($pending['relationship'] ?? '');
                $student->guardians()->syncWithoutDetaching([
                    $canonical->id => ['relationship' => $rel === '' ? null : $rel],
                ]);

                return app(StudentsService::class)->findForDetail($student->id);
            }

            if ($survivorSide === 'draft' && ! $draft) {
                throw new RuntimeException('No hay tutor borrador para conservar.');
            }

            $survivor = $survivorSide === 'draft' ? $draft : $canonical;
            $discard = $survivorSide === 'draft' ? $canonical : $draft;
            $survivorProfile = $survivor->profile;
            $discardProfile = $discard->profile;
            if (! $survivorProfile || ! $discardProfile) {
                throw new RuntimeException('Falta el perfil de uno de los tutores.');
            }

            $curp = Curp::normalize($canonical->profile?->national_id)
                ?: Curp::normalize($draft->profile?->national_id);
            if ($curp === '') {
                throw new RuntimeException('No hay CURP para fusionar.');
            }

            $nameSource = ($sources['name'] ?? $survivorSide) === 'draft' ? $draft->profile : $canonical->profile;
            $phoneSource = ($sources['phone'] ?? $survivorSide) === 'draft' ? $draft->profile : $canonical->profile;
            $emailSource = ($sources['email'] ?? $survivorSide) === 'draft' ? $draft->profile : $canonical->profile;
            $addressSide = $sources['address'] ?? 'none';

            // Move CURP off discard first to satisfy unique constraint.
            if ($discardProfile->national_id === $curp) {
                $discardProfile->national_id = null;
                $discardProfile->save();
            }

            $survivorProfile->fill([
                'first_name' => $nameSource->first_name,
                'last_name' => $nameSource->last_name,
                'second_last_name' => $nameSource->second_last_name,
                'phone_number' => $phoneSource->phone_number,
                'email' => $emailSource->email,
                'national_id' => $curp,
            ]);
            if ($addressSide === 'draft' && $draft->profile?->address_id) {
                $survivorProfile->address_id = $draft->profile->address_id;
            } elseif ($addressSide === 'canonical' && $canonical->profile?->address_id) {
                $survivorProfile->address_id = $canonical->profile->address_id;
            }
            $survivorProfile->save();

            $rel = $relationship;
            if ($rel === '') {
                $rel = (string) ($student->guardians()->whereKey($draft->id)->first()?->pivot?->relationship
                    ?? $student->guardians()->whereKey($canonical->id)->first()?->pivot?->relationship
                    ?? '');
            }

            // Reassign every student from discard onto survivor.
            foreach ($discard->students as $linkedStudent) {
                $pivotRel = $linkedStudent->pivot->relationship ?? $rel;
                $linkedStudent->guardians()->syncWithoutDetaching([
                    $survivor->id => ['relationship' => $pivotRel === '' ? null : $pivotRel],
                ]);
                $linkedStudent->guardians()->detach($discard->id);
            }

            // Ensure current student is on survivor.
            $student->guardians()->syncWithoutDetaching([
                $survivor->id => ['relationship' => $rel === '' ? null : $rel],
            ]);
            $student->guardians()->detach($discard->id);

            $discard->refresh();
            if (! $discard->students()->exists()) {
                $orphanProfile = $discard->profile;
                $discard->delete();
                if ($orphanProfile) {
                    $hasOtherRoles = $orphanProfile->teacher()->exists()
                        || $orphanProfile->staff()->exists()
                        || $orphanProfile->student()->exists()
                        || $orphanProfile->guardian()->exists();
                    if (! $hasOtherRoles) {
                        $orphanProfile->delete();
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
     * @param  array<string, mixed>  $pending
     * @param  array<string, string>  $sources
     */
    private function applyPendingFieldsToProfile(Profile $profile, array $pending, array $sources): void
    {
        $preferPending = fn (string $field) => ($sources[$field] ?? 'draft') === 'draft';

        if ($preferPending('name')) {
            if (! empty($pending['first_name'])) {
                $profile->first_name = trim((string) $pending['first_name']);
            }
            if (array_key_exists('last_name', $pending)) {
                $profile->last_name = trim((string) $pending['last_name']);
            }
            if (array_key_exists('second_last_name', $pending)) {
                $profile->second_last_name = trim((string) ($pending['second_last_name'] ?? '')) ?: null;
            }
        }
        if ($preferPending('phone') && array_key_exists('phone', $pending)) {
            $profile->phone_number = ($pending['phone'] ?? null) ?: null;
        }
        if ($preferPending('email') && array_key_exists('email', $pending)) {
            $profile->email = ($pending['email'] ?? null) ?: null;
        }
        $profile->save();
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
