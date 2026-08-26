<?php

namespace App\Http\Resources;

use App\Services\StudentPhotoPathService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Compact row for directory / grade lists. */
class StudentListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var \App\Models\Student $student */
        $student = $this->resource;
        $enrollment = $student->enrollments->where('status', 'active')->first()
            ?? $student->enrollments->first();
        $photos = app(StudentPhotoPathService::class);

        $grade = optional($enrollment?->classGroup?->gradeLevel)?->name ?? 'N/A';
        $group = optional($enrollment?->classGroup)?->name ?? 'N/A';

        return [
            'id' => $student->id,
            'credential_id' => $student->credential_id,
            'name' => trim(($student->profile?->first_name ?? '') . ' ' . ($student->profile?->last_name ?? '')),
            'birth_date' => $student->profile?->birth_date,
            'gender' => $student->profile?->gender,
            'phone' => $student->profile?->phone_number,
            'grade_level' => $grade,
            'class_group' => $group,
            /** @compat index payload */
            'current_grade' => $grade,
            'current_group' => $group,
            'photo_url' => $this->canSeeStudentPhotos($request)
                ? $photos->signedUrl($student, 'profile')
                : null,
        ];
    }

    private function canSeeStudentPhotos(Request $request): bool
    {
        $user = $request->user();

        return $user
            && ($user->can('view student photos') || $user->can('manage student photos'));
    }
}
