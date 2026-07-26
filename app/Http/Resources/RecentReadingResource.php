<?php

namespace App\Http\Resources;

use App\Models\Student;
use App\Services\StudentPhotoPathService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Carbon;

/**
 * Recent NFC reading event for the live attendance feed.
 *
 * @mixin \App\Models\RecentReading
 */
class RecentReadingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Student $student */
        $student = $this->student;
        $photoPathService = app(StudentPhotoPathService::class);

        $readAt = $this->read_at instanceof Carbon
            ? $this->read_at->toIso8601String()
            : null;

        return [
            'id' => $student->id,
            'reading_id' => $this->id,
            'credential_id' => $this->credential_id ?? $student->credential_id,
            'name' => trim(collect([
                $student->profile?->first_name,
                $student->profile?->last_name,
            ])->filter()->join(' ')),
            'photo_url' => $photoPathService->signedUrl($student, 'profile'),
            'gender' => $student->profile?->gender,
            'grade' => $student->currentGroup?->gradeLevel?->name,
            'group' => $student->currentGroup?->name,
            'event' => $this->event,
            'message' => $this->message,
            'registered_at' => $readAt,
            'read_at' => $readAt,
        ];
    }
}
