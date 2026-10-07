<?php

namespace App\Http\Resources\Staff;

use App\Enums\PersonPhotoKind;
use App\Http\Resources\People\ProfileResource;
use App\Models\Staff;
use App\Services\People\PersonPhotoPathService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffDetailResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Staff $staff */
        $staff = $this->resource;
        $personal = (new ProfileResource($staff->profile))->toArray($request);
        $address = $personal['address'] ?? null;
        unset($personal['address'], $personal['id']);

        return [
            'id' => $staff->id,
            'personal' => $personal,
            'address' => $address,
            'job' => [
                'position' => $staff->position,
                'department' => $staff->department,
                'status' => $staff->status instanceof \BackedEnum ? $staff->status->value : $staff->status,
            ],
            'photos' => app(PersonPhotoPathService::class)->currentUrls(PersonPhotoKind::Staff, $staff->id),
        ];
    }
}
