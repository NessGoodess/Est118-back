<?php

namespace App\Http\Resources;

use App\Models\Student;
use App\Services\StudentPhotoPathService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Normalized student payload for attendance panels (last attendance / history rows).
 *
 * @mixin \App\Models\GeneralAttendance
 */
class AttendanceStudentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Student $student */
        $student = $this->student;
        $photoPathService = app(StudentPhotoPathService::class);

        $registeredAt = $this->scanned_at instanceof Carbon
            ? $this->scanned_at->toIso8601String()
            : null;

        return [
            'id' => $student->id,
            'credential_id' => $student->credential_id,
            'name' => trim(collect([
                $student->profile?->first_name,
                $student->profile?->last_name,
            ])->filter()->join(' ')),
            'photo_url' => $photoPathService->signedUrl($student, 'profile'),
            'gender' => $student->profile?->gender,
            'grade' => $student->currentGroup?->gradeLevel?->name,
            'group' => $student->currentGroup?->name,
            'registered_at' => $registeredAt,
        ];
    }
}
