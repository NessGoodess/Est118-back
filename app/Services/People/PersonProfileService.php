<?php

namespace App\Services\People;

use App\Models\Profile;
use RuntimeException;

class PersonProfileService
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Profile
    {
        $curp = strtoupper(trim((string) $data['curp']));
        $this->assertCurpAvailable($curp);

        return Profile::query()->create($this->attributes($data, $curp));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Profile $profile, array $data): Profile
    {
        $curp = array_key_exists('curp', $data)
            ? strtoupper(trim((string) $data['curp']))
            : (string) $profile->national_id;

        if ($curp !== '' && $curp !== (string) $profile->national_id) {
            $this->assertCurpAvailable($curp, $profile->id);
        }

        $profile->fill($this->attributes($data, $curp !== '' ? $curp : null, $profile))->save();

        return $profile;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data, ?string $curp, ?Profile $current = null): array
    {
        $paterno = trim((string) ($data['last_name'] ?? ''));
        $materno = array_key_exists('second_last_name', $data)
            ? trim((string) ($data['second_last_name'] ?? ''))
            : (string) ($current?->second_last_name ?? '');

        return [
            'national_id' => $curp,
            'first_name' => $data['first_name'] ?? $current?->first_name,
            'last_name' => $paterno !== '' ? $paterno : $current?->last_name,
            'second_last_name' => $materno !== '' ? $materno : null,
            'birth_date' => $data['birth_date'] ?? $current?->birth_date,
            'gender' => $data['gender'] ?? $current?->gender,
            'email' => array_key_exists('email', $data) ? (($data['email'] ?? null) ?: null) : $current?->email,
            'phone_number' => array_key_exists('phone', $data) ? (($data['phone'] ?? null) ?: null) : $current?->phone_number,
        ];
    }

    private function assertCurpAvailable(string $curp, ?int $ignoreProfileId = null): void
    {
        $query = Profile::query()->where('national_id', $curp);
        if ($ignoreProfileId !== null) {
            $query->where('id', '!=', $ignoreProfileId);
        }
        if ($query->exists()) {
            throw new RuntimeException('Ya existe una persona con esa CURP.');
        }
    }
}
