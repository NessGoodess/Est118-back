<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;

class NotificationDispatchService
{
    /**
     * Notifica a todos los usuarios que tengan un permiso (vía rol o directo).
     *
     * @param  User|null  $except  Usuario a excluir (p. ej. quien creó el registro).
     */
    public function notifyUsersWithPermission(
        string $permission,
        Notification $notification,
        ?User $except = null
    ): int {
        $users = $this->usersWithPermission($permission, $except);

        foreach ($users as $user) {
            $user->notify($notification);
        }

        return $users->count();
    }

    /**
     * @return Collection<int, User>
     */
    public function usersWithPermission(string $permission, ?User $except = null): Collection
    {
        $query = User::permission($permission);

        if ($except) {
            $query->where('id', '!=', $except->id);
        }

        return $query->get();
    }
}
