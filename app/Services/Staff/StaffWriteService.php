<?php

namespace App\Services\Staff;

use App\Enums\StaffStatus;
use App\Models\Staff;
use App\Services\People\PersonAddressService;
use App\Services\People\PersonProfileService;
use Illuminate\Support\Facades\DB;

class StaffWriteService
{
    public function __construct(
        private readonly PersonProfileService $profiles,
        private readonly PersonAddressService $addresses,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Staff
    {
        return DB::transaction(function () use ($data) {
            $profile = $this->profiles->create($data);
            $this->addresses->upsertFor($profile, $data);

            $staff = Staff::query()->create([
                'profile_id' => $profile->id,
                'position' => $data['position'],
                'department' => ($data['department'] ?? null) ?: null,
                'status' => $data['status'] ?? StaffStatus::ACTIVE,
            ]);

            return $staff->load('profile.address');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Staff $staff, array $data): Staff
    {
        return DB::transaction(function () use ($staff, $data) {
            $staff->load('profile.address');

            if (isset($data['personal']) && is_array($data['personal'])) {
                $this->profiles->update($staff->profile, $data['personal']);
            }
            if (isset($data['address']) && is_array($data['address'])) {
                $this->addresses->upsertFor($staff->profile, $data['address']);
            }
            if (isset($data['job']) && is_array($data['job'])) {
                $job = $data['job'];
                $staff->fill([
                    'position' => $job['position'] ?? $staff->position,
                    'department' => array_key_exists('department', $job) ? (($job['department'] ?? null) ?: null) : $staff->department,
                    'status' => $job['status'] ?? $staff->status,
                ])->save();
            }

            return $staff->fresh(['profile.address']);
        });
    }
}
