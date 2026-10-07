<?php

namespace App\Http\Resources\Teachers;

use App\Models\SchoolClass;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherClassOptionResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SchoolClass $class */
        $class = $this->resource;
        $group = $class->classGroup;
        $current = $class->teacher?->profile;

        return [
            'id' => $class->id,
            'subject' => $class->subject?->name,
            'subject_code' => $class->subject?->code,
            'grade' => $group?->gradeLevel?->name,
            'group' => $group?->name,
            'academic_year_id' => $group?->academic_year_id,
            'teacher_id' => $class->teacher_id,
            'teacher_name' => $current
                ? trim($current->first_name.' '.$current->last_name)
                : null,
        ];
    }
}
