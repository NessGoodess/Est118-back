<?php

namespace App\Services\Staff;

use App\Models\Staff;
use Illuminate\Database\Eloquent\Collection;

class StaffQueryService
{
    /**
     * @return Collection<int, Staff>
     */
    public function list(?string $status = null): Collection
    {
        $query = Staff::query()
            ->with('profile')
            ->join('profiles', 'profiles.id', '=', 'staff.profile_id')
            ->orderBy('profiles.last_name')
            ->orderBy('profiles.first_name')
            ->select('staff.*');

        if ($status !== null && $status !== '') {
            $query->where('staff.status', $status);
        }

        return $query->get();
    }

    public function detail(Staff $staff): Staff
    {
        return $staff->load('profile.address');
    }
}
