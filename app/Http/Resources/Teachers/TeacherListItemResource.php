<?php

namespace App\Http\Resources\Teachers;

use App\Models\Teacher;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Teacher $teacher */
        $teacher = $this->resource;
        $profile = $teacher->profile;

        return [
            'id' => $teacher->id,
            'first_name' => $profile?->first_name,
            'last_name' => $profile?->last_name,
            'curp' => $profile?->national_id,
            'phone' => $profile?->phone_number,
            'email' => $profile?->email,
            'employee' => $teacher->employee,
            'classroom' => $teacher->classroom,
            'status' => $teacher->status instanceof \BackedEnum ? $teacher->status->value : $teacher->status,
            'classes_count' => (int) ($teacher->school_classes_count ?? $teacher->schoolClasses->count()),
        ];
    }
}
