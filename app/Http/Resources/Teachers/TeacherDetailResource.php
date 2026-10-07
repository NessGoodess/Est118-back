<?php

namespace App\Http\Resources\Teachers;

use App\Enums\PersonPhotoKind;
use App\Http\Resources\People\ProfileResource;
use App\Models\Teacher;
use App\Services\People\PersonPhotoPathService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeacherDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Teacher $teacher */
        $teacher = $this->resource;
        $personal = (new ProfileResource($teacher->profile))->toArray($request);
        $address = $personal['address'] ?? null;
        unset($personal['address'], $personal['id']);

        return [
            'id' => $teacher->id,
            'personal' => $personal,
            'address' => $address,
            'job' => [
                'employee' => $teacher->employee,
                'classroom' => $teacher->classroom,
                'status' => $teacher->status instanceof \BackedEnum ? $teacher->status->value : $teacher->status,
            ],
            'classes' => TeacherClassOptionResource::collection($teacher->schoolClasses ?? [])->resolve(),
            'photos' => app(PersonPhotoPathService::class)->currentUrls(PersonPhotoKind::Teachers, $teacher->id),
        ];
    }
}
