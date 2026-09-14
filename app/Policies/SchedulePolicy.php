<?php

namespace App\Policies;

use App\Models\Schedule;
use App\Models\User;

class SchedulePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can('view attendance');
    }

    public function view(User $user, Schedule $schedule): bool
    {
        if ($user->can('view all schedules') || $user->can('view group schedules')) {
            return true;
        }

        return $user->can('view own schedules') && $user->ownsSchedule($schedule);
    }

    public function create(User $user): bool
    {
        return $user->can('view all schedules');
    }

    public function update(User $user, Schedule $schedule): bool
    {
        return $this->view($user, $schedule);
    }

    public function delete(User $user, Schedule $schedule): bool
    {
        return $user->can('view all schedules');
    }

    public function restore(User $user, Schedule $schedule): bool
    {
        return $user->can('view all schedules');
    }

    public function forceDelete(User $user, Schedule $schedule): bool
    {
        return $user->can('view all schedules');
    }
}
