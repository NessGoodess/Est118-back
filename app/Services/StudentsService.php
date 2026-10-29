<?php

namespace App\Services;

use App\Exceptions\GuardianCurpConflictException;
use App\Models\AcademicYear;
use App\Models\Guardian;
use App\Models\Profile;
use App\Models\Student;
use App\Services\People\PersonAddressService;
use App\Services\People\PersonProfileService;
use App\Services\People\StudentGuardianPrimaryService;
use App\Support\Curp;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class StudentsService
{
    /**
     * Legacy helper kept for older callers.
     */
    public function generatePrivateImageUrl($grade, $group, $photo)
    {
        $photoPath = ($grade && $group && $photo)
            ? 'photos/students/' . rawurlencode($grade)
            . '/' . rawurlencode($group)
            . '/' . rawurlencode($photo)
            : 'photos/students/default.png';

        return $photoPath;
    }

    public function findForDetail(int $id): Student
    {
        return Student::with([
            'profile.address',
            'enrollments.classGroup.gradeLevel',
            'enrollments.classGroup.academicYear',
            'enrollments.classGroup.schoolClasses.subject',
            'workshopEnrollments.workshop',
            'guardians.profile.address',
            'siblings.profile',
            'siblings.currentEnrollment.classGroup.gradeLevel',
        ])->findOrFail($id);
    }

    /**
     * @param  array{profile?: array<string, mixed>, address?: array<string, mixed>}  $data
     */
    public function updateProfileAndAddress(Student $student, array $data): Student
    {
        $student->load('profile.address');
        $profile = $student->profile;

        if (! $profile) {
            throw new RuntimeException('El alumno no tiene perfil asociado.');
        }

        DB::transaction(function () use ($profile, $data) {
            if (! empty($data['profile']) && is_array($data['profile'])) {
                $personal = $data['profile'];
                app(PersonProfileService::class)->update($profile, Arr::except($personal, ['phone_second_number']));

                // Only students keep a second phone, so the shared person service does not know it.
                if (array_key_exists('phone_second_number', $personal)) {
                    $profile->phone_second_number = ($personal['phone_second_number'] ?? null) ?: null;
                    $profile->save();
                }
            }

            if (! empty($data['address']) && is_array($data['address'])) {
                app(PersonAddressService::class)->upsertFor($profile, $data['address']);
            }
        });

        return $this->findForDetail($student->id);
    }

    /**
     * Create or reuse a guardian profile and link it to this student.
     *
     * @param  array{
     *   first_name: string,
     *   last_name: string,
     *   second_last_name?: string|null,
     *   curp?: string|null,
     *   birth_date?: string|null,
     *   gender?: string|null,
     *   phone?: string|null,
     *   email?: string|null,
     *   relationship?: string|null,
     *   address_mode: 'student'|'new',
     *   address?: array<string, mixed>
     * }  $data
     */
    public function storeGuardian(Student $student, array $data): Student
    {
        return DB::transaction(function () use ($student, $data) {
            $student->loadMissing('profile');
            $studentProfile = $student->profile;
            if (! $studentProfile) {
                throw new RuntimeException('El alumno no tiene perfil asociado.');
            }

            $curp = Curp::normalize($data['curp'] ?? null);
            $attributes = [
                'first_name' => trim($data['first_name']),
                'last_name' => trim($data['last_name']),
                'second_last_name' => trim((string) ($data['second_last_name'] ?? '')) ?: null,
                'birth_date' => $data['birth_date'] ?? null,
                'gender' => ($data['gender'] ?? null) ?: null,
                'phone_number' => ($data['phone'] ?? null) ?: null,
                'email' => ($data['email'] ?? null) ?: null,
            ];

            if ($curp !== '') {
                app(GuardianCurpMergeService::class)->assertCurpFreeOrConflict($curp, [
                    ...$attributes,
                    'phone' => $attributes['phone_number'],
                    'email' => $attributes['email'],
                    'relationship' => $data['relationship'] ?? null,
                ]);
            }

            // Without a CURP there is no safe key to match an existing person.
            $guardianProfile = $curp !== ''
                ? Profile::query()->firstOrCreate(['national_id' => $curp], $attributes)
                : Profile::query()->create([...$attributes, 'national_id' => null]);

            $relationship = trim((string) ($data['relationship'] ?? ''));
            $relationship = $relationship === '' ? null : $relationship;
            $guardian = Guardian::query()->firstOrCreate(
                ['profile_id' => $guardianProfile->id],
                ['Kinship' => $relationship]
            );

            $alreadyLinked = $student->guardians()->where('guardians.id', $guardian->id)->exists();
            $pivot = $alreadyLinked
                ? ['relationship' => $relationship]
                : $student->guardianAttachPivot($relationship);

            $wantPrimary = array_key_exists('is_primary', $data) && (bool) $data['is_primary'];
            $wantSecondary = array_key_exists('is_secondary', $data) && (bool) $data['is_secondary'];
            if ($wantPrimary) {
                $wantSecondary = false;
            }

            if ($wantPrimary) {
                DB::table('guardian_student')
                    ->where('student_id', $student->id)
                    ->where('guardian_id', '!=', $guardian->id)
                    ->update(['is_primary' => false]);
                $pivot['is_primary'] = true;
                $pivot['is_secondary'] = false;
            } elseif ($wantSecondary) {
                DB::table('guardian_student')
                    ->where('student_id', $student->id)
                    ->where('guardian_id', '!=', $guardian->id)
                    ->update(['is_secondary' => false]);
                $pivot['is_primary'] = false;
                $pivot['is_secondary'] = true;
            } elseif (array_key_exists('is_secondary', $data)) {
                $pivot['is_secondary'] = false;
            }

            $student->guardians()->syncWithoutDetaching([
                $guardian->id => $pivot,
            ]);

            // First tutor / data gaps: always leave exactly one primary.
            app(StudentGuardianPrimaryService::class)->ensureExactlyOne((int) $student->id);

            if (($data['address_mode'] ?? '') === 'student') {
                if (! $studentProfile->address_id) {
                    throw new RuntimeException('El alumno no tiene domicilio capturado.');
                }
                $guardianProfile->address_id = $studentProfile->address_id;
                $guardianProfile->save();
            } else {
                app(PersonAddressService::class)->upsertFor(
                    $guardianProfile,
                    is_array($data['address'] ?? null) ? $data['address'] : []
                );
            }

            return $this->findForDetail($student->id);
        });
    }

    /**
     * @param  array{first_name: string, last_name: string, second_last_name?: string|null, national_id?: string|null, relationship?: string|null, email?: string|null, phone?: string|null}  $data
     */
    public function updateGuardian(Student $student, Guardian $guardian, array $data): Student
    {
        return app(GuardianCurpMergeService::class)->updateGuardianOrConflict($student, $guardian, $data);
    }

    /**
     * Unlink a guardian from this student only (does not delete the guardian record).
     * If the removed tutor was primary, promote the next remaining one.
     * Desvincular un tutor de este alumno solo (no elimina el registro del tutor).
     */
    public function detachGuardian(Student $student, Guardian $guardian): Student
    {
        return DB::transaction(function () use ($student, $guardian) {
            $linked = $student->guardians()->where('guardians.id', $guardian->id)->first();
            if (! $linked) {
                throw new RuntimeException('Este tutor no está vinculado a este alumno.');
            }

            $wasPrimary = (bool) ($linked->pivot->is_primary ?? false);
            $student->guardians()->detach($guardian->id);

            if ($wasPrimary) {
                app(StudentGuardianPrimaryService::class)->ensureExactlyOne((int) $student->id);
            }

            return $this->findForDetail($student->id);
        });
    }

    public function listIndex(): Collection
    {
        return Student::with([
            'profile:id,first_name,last_name,second_last_name,profile_picture,updated_at',
            'enrollments' => fn ($q) => $q->whereIn('status', ['active', 'pre_enrolled'])->with([
                'classGroup:id,name,grade_level_id,academic_year_id',
                'classGroup.gradeLevel:id,name',
                'classGroup.academicYear',
            ]),
        ])->get();
    }

    /**
     * Explicit cycle, otherwise the active one, otherwise the most recent.
     */
    public function resolveYearId(?int $yearId): ?int
    {
        if ($yearId !== null) {
            return AcademicYear::query()->whereKey($yearId)->exists() ? $yearId : null;
        }

        $activeId = AcademicYear::query()->where('is_active', true)->value('id');
        if ($activeId !== null) {
            return (int) $activeId;
        }

        $latestId = AcademicYear::query()
            ->orderByDesc('starts_on')
            ->orderByDesc('year_start')
            ->value('id');

        return $latestId !== null ? (int) $latestId : null;
    }

    /**
     * Students enrolled in a grade for one academic year, any status unless filtered.
     *
     * The year is taken from the class group so older enrollments with a null
     * academic_year_id still match the cycle they belonged to.
     *
     * @param  list<string>|null  $statuses
     */
    public function listByGrade(int $gradeId, int $yearId, ?array $statuses = null): Collection
    {
        return Student::query()
            ->with([
                'profile',
                'enrollments' => function ($query) use ($gradeId, $yearId, $statuses) {
                    $query->whereHas('classGroup', function (Builder $group) use ($gradeId, $yearId) {
                        $group->where('grade_level_id', $gradeId)
                            ->where('academic_year_id', $yearId);
                    });
                    if ($statuses) {
                        $query->whereIn('status', $statuses);
                    }
                    $query->with([
                        'classGroup.gradeLevel',
                        'classGroup.academicYear',
                    ]);
                },
            ])
            ->whereHas('enrollments', function ($query) use ($gradeId, $yearId, $statuses) {
                if ($statuses) {
                    $query->whereIn('status', $statuses);
                }
                $query->whereHas('classGroup', function (Builder $group) use ($gradeId, $yearId) {
                    $group->where('grade_level_id', $gradeId)
                        ->where('academic_year_id', $yearId);
                });
            })
            ->get();
    }

    public function photoStatusPayload(int $studentId): array
    {
        $student = Student::with([
            'profile:id,first_name,last_name,second_last_name,profile_picture,updated_at',
            'currentEnrollment.classGroup.gradeLevel:id,name',
            'currentEnrollment.classGroup:id,name,grade_level_id',
        ])->findOrFail($studentId);

        $pathService = app(StudentPhotoPathService::class);
        $hasPhoto = $pathService->fileExists($student, 'profile')
            || $pathService->fileExists($student, 'original');

        return [
            'student_id' => $student->id,
            'student_name' => $student->profile?->fullName() ?? '',
            'has_photo' => $hasPhoto,
            'action_label' => $hasPhoto ? 'Renovar' : 'Capturar',
            'photo_url' => $hasPhoto
                ? $pathService->signedUrl($student, 'profile')
                : null,
            'grade' => $student->currentEnrollment?->classGroup?->gradeLevel?->name,
            'group' => $student->currentEnrollment?->classGroup?->name,
        ];
    }

    /**
     * @deprecated Prefer StudentPhotoPathService::signedUrl($student, $size)
     * @param  mixed  $updatedAt
     */
    public function signedPhotoUrl(int $studentId, ?string $photo, $updatedAt, string $size = 'thumb'): ?string
    {
        if (! $photo) {
            return null;
        }

        $student = Student::with([
            'profile:id,profile_picture,updated_at',
            'currentEnrollment.classGroup.gradeLevel:id,name',
            'currentEnrollment.classGroup:id,name,grade_level_id',
        ])->find($studentId);

        if (! $student) {
            return null;
        }

        return app(StudentPhotoPathService::class)->signedUrl($student, $size);
    }
}
