<?php

namespace App\Http\Resources;

use App\Services\StudentsService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Full expediente payload for GET/PATCH /students/{id}. */
class StudentDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\Student $student */
        $student = $this->resource;
        $profile = $student->profile;
        $photos = app(StudentsService::class);
        $currentEnrollment = $student->enrollments->where('status', 'active')->first();
        $address = $profile?->relationLoaded('address') ? $profile->address : null;

        return [
            'student_info' => [
                'id' => $student->id,
                'credential_id' => $student->credential_id,
                'full_name' => trim(($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? '')),
                'first_name' => $profile?->first_name,
                'last_name' => $profile?->last_name,
                /** @compat legacy campo name */
                'name' => trim(($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? '')),
                'national_id' => $profile?->national_id,
                'birth_date' => $profile?->birth_date,
                'gender' => $profile?->gender,
                'phone' => $profile?->phone_number,
                'phone_secondary' => $profile?->phone_second_number,
                'email' => $profile?->email,
                'profile_picture_filename' => $profile?->profile_picture,
                'profile_updated_at' => $profile?->updated_at,
            ],
            'photos' => $this->canSeeStudentPhotos($request) ? [
                'thumbnail_url' => $photos->signedPhotoUrl($student->id, $profile?->profile_picture, $profile?->updated_at, 'thumb'),
                'profile_url' => $photos->signedPhotoUrl($student->id, $profile?->profile_picture, $profile?->updated_at, 'profile'),
                'original_url' => $photos->signedPhotoUrl($student->id, $profile?->profile_picture, $profile?->updated_at, 'original'),
            ] : null,
            'address_detail' => $address ? $address->only([
                'street_type',
                'street_name',
                'house_number',
                'apartament_number',
                'neighborhood_type',
                'neighborhood_name',
                'postal_code',
                'city',
                'state',
            ]) : null,
            'current_enrollment' => $currentEnrollment ? [
                'enrollment_id' => $currentEnrollment->id,
                'grade_level' => optional($currentEnrollment->classGroup?->gradeLevel)->name,
                'class_group' => optional($currentEnrollment->classGroup)->name,
                'academic_year' => optional($currentEnrollment->classGroup?->academicYear)->description,
                'recorded_at' => $currentEnrollment->created_at,
                'updated_at' => $currentEnrollment->updated_at,
                'is_new_admission' => $currentEnrollment->is_new_admission,
                'is_approved' => $currentEnrollment->is_approved,
                'promotion_result' => $currentEnrollment->promotion_result instanceof \BackedEnum
                    ? $currentEnrollment->promotion_result->value
                    : $currentEnrollment->promotion_result,
            ] : null,
            'subjects' => $currentEnrollment
                ? $currentEnrollment->classGroup->schoolClasses
                    ->map(fn ($class) => $class->subject?->name)
                    ->filter()
                    ->unique()
                    ->values()
                : [],
            'all_enrollments' => $student->enrollments->sortByDesc('id')->values()->map(function ($enrollment) {
                return [
                    'id' => $enrollment->id,
                    'status' => $enrollment->status instanceof \BackedEnum
                        ? $enrollment->status->value
                        : (string) $enrollment->status,
                    'grade_level' => optional(optional($enrollment->classGroup)->gradeLevel)->name,
                    'class_group' => optional($enrollment->classGroup)->name,
                    'academic_year' => optional(optional($enrollment->classGroup)->academicYear)->description,
                    'is_new_admission' => $enrollment->is_new_admission,
                    'is_approved' => $enrollment->is_approved,
                    'promotion_result' => $enrollment->promotion_result instanceof \BackedEnum
                        ? $enrollment->promotion_result->value
                        : $enrollment->promotion_result,
                    'created_at' => $enrollment->created_at,
                    'updated_at' => $enrollment->updated_at,
                ];
            }),
            'guardians' => $student->guardians->map(function ($guardian) {
                $p = $guardian->profile;

                return [
                    'name' => trim(($p?->first_name ?? '') . ' ' . ($p?->last_name ?? '')),
                    'relationship' => $guardian->pivot->relationship,
                    'phone' => $p?->phone_number,
                ];
            }),
        ];
    }

    private function canSeeStudentPhotos(Request $request): bool
    {
        $user = $request->user();

        return $user
            && ($user->can('view student photos') || $user->can('manage student photos'));
    }
}
