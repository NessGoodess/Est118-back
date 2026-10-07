<?php

namespace App\Http\Resources\Staff;

use App\Models\Staff;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class StaffListItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Staff $staff */
        $staff = $this->resource;
        $profile = $staff->profile;

        return [
            'id' => $staff->id,
            'first_name' => $profile?->first_name,
            'last_name' => $profile?->last_name,
            'curp' => $profile?->national_id,
            'phone' => $profile?->phone_number,
            'email' => $profile?->email,
            'position' => $staff->position,
            'department' => $staff->department,
            'status' => $staff->status instanceof \BackedEnum ? $staff->status->value : $staff->status,
        ];
    }
}
