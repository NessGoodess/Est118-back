<?php

namespace App\Services\People;

use App\Models\Address;
use App\Models\Profile;

class PersonAddressService
{
    private const FIELDS = [
        'street_type',
        'street_name',
        'house_number',
        'unit_number',
        'neighborhood_type',
        'neighborhood_name',
        'postal_code',
        'city',
        'state',
    ];

    /**
     * Keys missing from $data keep their current value, so a partial PATCH is safe.
     *
     * @param  array<string, mixed>  $data
     */
    public function upsertFor(Profile $profile, array $data): Address
    {
        $address = $profile->address;
        $payload = [];
        foreach (self::FIELDS as $field) {
            $payload[$field] = array_key_exists($field, $data)
                ? (($data[$field] ?? null) ?: null)
                : $address?->{$field};
        }

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
