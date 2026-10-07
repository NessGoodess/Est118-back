<?php

namespace App\Services\Staff;

use App\Enums\StaffStatus;
use App\Models\Staff;
use RuntimeException;

class StaffStatusService
{
    public function setStatus(Staff $staff, string $status): Staff
    {
        $enum = StaffStatus::tryFrom($status);
        if ($enum === null) {
            throw new RuntimeException('El estado del personal no es válido.');
        }
        $staff->status = $enum;
        $staff->save();

        return $staff->fresh(['profile.address']);
    }

    public function deactivate(Staff $staff): Staff
    {
        return $this->setStatus($staff, StaffStatus::INACTIVE->value);
    }

    public function reactivate(Staff $staff): Staff
    {
        return $this->setStatus($staff, StaffStatus::ACTIVE->value);
    }
}
