<?php

namespace App\Services\People;

use App\Models\Address;
use App\Models\Profile;

class PersonAddressService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function upsertFor(Profile $profile, array $data): Address
    {
        $payload = [
            'street_type' => $data['street_type'],
            'street_name' => $data['street_name'],
            'house_number' => $data['house_number'],
            'unit_number' => ($data['unit_number'] ?? null) ?: null,
            'neighborhood_type' => $data['neighborhood_type'],
            'neighborhood_name' => $data['neighborhood_name'],
            'postal_code' => $data['postal_code'],
            'city' => $data['city'],
            'state' => $data['state'],
        ];

        $address = $profile->address;
        if ($address) {
            $address->fill($payload)->save();

            return $address;
        }

        $address = Address::query()->create($payload);
        $profile->address_id = $address->id;
        $profile->save();

        return $address;
    }
}
