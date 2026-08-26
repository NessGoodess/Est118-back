<?php

namespace App\Services;

use App\Models\Address;
use App\Models\Student;
use Illuminate\Support\Collection;
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
            'guardians.profile',
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

        if (! empty($data['profile']) && is_array($data['profile'])) {
            $profile->fill($data['profile']);
            $profile->save();
        }

        if (array_key_exists('address', $data) && is_array($data['address'])) {
            $addressPayload = $data['address'];
            if (array_key_exists('apartament_number', $addressPayload) && ! array_key_exists('unit_number', $addressPayload)) {
                $addressPayload['unit_number'] = $addressPayload['apartament_number'];
            }
            unset($addressPayload['apartament_number']);
            if ($profile->address) {
                $profile->address->fill($addressPayload);
                $profile->address->save();
            } else {
                $address = Address::create($addressPayload);
                $profile->address_id = $address->id;
                $profile->save();
            }
        }

        return $this->findForDetail($student->id);
    }

    public function listIndex(): Collection
    {
        return Student::with([
            'profile:id,first_name,last_name,profile_picture,updated_at',
            'enrollments' => fn ($q) => $q->where('status', 'active')->with([
                'classGroup:id,name,grade_level_id',
                'classGroup.gradeLevel:id,name',
            ]),
        ])->get();
    }

    public function listByGrade(int $gradeId): Collection
    {
        return Student::with([
            'profile',
            'enrollments.classGroup.gradeLevel',
            'enrollments.classGroup.academicYear',
            'enrollments.classGroup.schoolClasses.subject',
        ])
            ->whereHas('enrollments', function ($q) use ($gradeId) {
                $q->where('status', 'active')
                    ->whereHas('classGroup', function ($q) use ($gradeId) {
                        $q->where('grade_level_id', $gradeId);
                    });
            })
            ->get();
    }

    public function photoStatusPayload(int $studentId): array
    {
        $student = Student::with([
            'profile:id,first_name,last_name,profile_picture,updated_at',
            'currentEnrollment.classGroup.gradeLevel:id,name',
            'currentEnrollment.classGroup:id,name,grade_level_id',
        ])->findOrFail($studentId);

        $pathService = app(StudentPhotoPathService::class);
        $hasPhoto = $pathService->fileExists($student, 'profile')
            || $pathService->fileExists($student, 'original');

        return [
            'student_id' => $student->id,
            'student_name' => trim(($student->profile?->first_name ?? '') . ' ' . ($student->profile?->last_name ?? '')),
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
