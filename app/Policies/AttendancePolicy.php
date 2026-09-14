<?php

namespace App\Policies;

use App\Models\Attendance;
use App\Models\User;

class AttendancePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view attendance');
    }

    public function view(User $user, Attendance $attendance): bool
    {
        if (! $user->can('view attendance')) {
            return false;
        }

        $attendance->loadMissing('schedule.schoolClass');

        return $attendance->schedule
            ? $user->can('view', $attendance->schedule)
            : false;
    }

    public function create(User $user): bool
    {
        return $user->can('edit attendance');
    }

    public function update(User $user, Attendance $attendance): bool
    {
        return $this->create($user) && $this->view($user, $attendance);
    }

    public function delete(User $user, Attendance $attendance): bool
    {
        return $user->can('view all schedules');
    }

    public function restore(User $user, Attendance $attendance): bool
    {
        return $user->can('view all schedules');
    }

    public function forceDelete(User $user, Attendance $attendance): bool
    {
        return $user->can('view all schedules');
    }
}
