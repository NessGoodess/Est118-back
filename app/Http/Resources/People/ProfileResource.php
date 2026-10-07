<?php

namespace App\Http\Resources\People;

use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProfileResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var Profile $profile */
        $profile = $this->resource;
        $address = $profile->relationLoaded('address') ? $profile->address : null;

        return [
            'id' => $profile->id,
            'first_name' => $profile->first_name,
            'last_name' => $profile->last_name,
            'second_last_name' => '',
            'curp' => $profile->national_id,
            'birth_date' => $profile->birth_date,
            'gender' => $profile->gender,
            'phone' => $profile->phone_number,
            'email' => $profile->email,
            'address' => $address ? [
                'street_type' => $address->street_type,
                'street_name' => $address->street_name,
                'house_number' => $address->house_number,
                'unit_number' => $address->unit_number,
                'neighborhood_type' => $address->neighborhood_type,
                'neighborhood_name' => $address->neighborhood_name,
                'postal_code' => $address->postal_code,
                'city' => $address->city,
                'state' => $address->state,
            ] : null,
        ];
    }
}
