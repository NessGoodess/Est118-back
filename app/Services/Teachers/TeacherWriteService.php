<?php

namespace App\Services\Teachers;

use App\Enums\TeacherStatus;
use App\Models\Teacher;
use App\Services\People\PersonAddressService;
use App\Services\People\PersonProfileService;
use Illuminate\Support\Facades\DB;

class TeacherWriteService
{
    public function __construct(
        private readonly PersonProfileService $profiles,
        private readonly PersonAddressService $addresses,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Teacher
    {
        return DB::transaction(function () use ($data) {
            $profile = $this->profiles->create($data);
            $this->addresses->upsertFor($profile, $data);

            $teacher = Teacher::query()->create([
                'profile_id' => $profile->id,
                'employee' => ($data['employee'] ?? null) ?: null,
                'classroom' => ($data['classroom'] ?? null) ?: null,
                'status' => $data['status'] ?? TeacherStatus::ACTIVE,
            ]);

            return $teacher->load('profile.address');
        });
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Teacher $teacher, array $data): Teacher
    {
        return DB::transaction(function () use ($teacher, $data) {
            $teacher->load('profile.address');

            if (isset($data['personal']) && is_array($data['personal'])) {
                $this->profiles->update($teacher->profile, $data['personal']);
            }
            if (isset($data['address']) && is_array($data['address'])) {
                $this->addresses->upsertFor($teacher->profile, $data['address']);
            }
            if (isset($data['job']) && is_array($data['job'])) {
                $job = $data['job'];
                $teacher->fill([
                    'employee' => array_key_exists('employee', $job) ? (($job['employee'] ?? null) ?: null) : $teacher->employee,
                    'classroom' => array_key_exists('classroom', $job) ? (($job['classroom'] ?? null) ?: null) : $teacher->classroom,
                    'status' => $job['status'] ?? $teacher->status,
                ])->save();
            }

            return $teacher->fresh(['profile.address', 'schoolClasses.subject', 'schoolClasses.classGroup.gradeLevel']);
        });
    }
}
