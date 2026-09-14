<?php

namespace App\Services;

use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\WorkshopEnrollment;
use Illuminate\Support\Facades\Auth;

class WorkshopEnrollmentWriter
{
    public function upsert(
        int $studentId,
        int $academicYearId,
        int $workshopId,
        WorkshopEnrollmentSource $source,
        WorkshopEnrollmentStatus $status,
        ?string $notes = null,
        ?int $assignedBy = null,
        bool $protectManual = true,
    ): WorkshopEnrollment {
        $row = WorkshopEnrollment::query()->firstOrNew([
            'student_id' => $studentId,
            'academic_year_id' => $academicYearId,
        ]);

        if (
            $protectManual
            && $row->exists
            && $row->source === WorkshopEnrollmentSource::Manual
            && $source !== WorkshopEnrollmentSource::Manual
        ) {
            return $row;
        }

        $row->fill([
            'workshop_id' => $workshopId,
            'source' => $source,
            'status' => $status,
            'notes' => $notes,
            'assigned_by' => $assignedBy ?? Auth::id(),
        ]);
        $row->save();

        return $row;
    }
}
