<?php

namespace App\Services;

use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\Enrollment;
use App\Models\Student;
use App\Models\Workshop;
use App\Models\WorkshopEnrollment;
use App\Models\WorkshopOffering;
use App\Support\WorkshopLetterHint;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

class AssignStudentWorkshopService
{
    public function __construct(
        private readonly WorkshopEnrollmentWriter $writer
    ) {}

    /**
     * @return array{enrollment: WorkshopEnrollment, warnings: list<string>}
     */
    public function assign(Student $student, int $workshopId, ?string $notes = null, bool $force = false, ?int $academicYearId = null,): array
    {
        $enrollment = Enrollment::query()
            ->with('classGroup')
            ->where('student_id', $student->id)
            ->where('status', 'active')
            ->when($academicYearId, fn($q) => $q->where('academic_year_id', $academicYearId))
            ->latest('id')
            ->first();

        if (! $enrollment) {
            throw new RuntimeException('El alumno no tiene inscripción activa.');
        }

        $yearId = (int) $enrollment->academic_year_id;
        $workshop = Workshop::query()->whereKey($workshopId)->where('is_active', true)->first();
        if (! $workshop) {
            throw new RuntimeException('El taller no existe o está inactivo.');
        }

        $offering = WorkshopOffering::query()
            ->where('academic_year_id', $yearId)
            ->where('workshop_id', $workshop->id)
            ->first();

        $warnings = [];
        $occupied = WorkshopEnrollment::query()
            ->where('academic_year_id', $yearId)
            ->where('workshop_id', $workshop->id)
            ->where('status', WorkshopEnrollmentStatus::Assigned->value)
            ->where('student_id', '!=', $student->id)
            ->count();

        if ($offering && $offering->capacity !== null && $occupied >= (int) $offering->capacity && ! $force) {
            throw new RuntimeException(
                "El taller {$workshop->name} no tiene cupo disponible. Usa force=true para asignar de todos modos."
            );
        }

        if ($offering && $offering->capacity !== null && $occupied >= (int) $offering->capacity && $force) {
            $warnings[] = 'over_capacity';
        }

        $expected = WorkshopLetterHint::expectedCode((string) ($enrollment->classGroup?->name ?? ''));
        if ($expected && $workshop->code !== $expected) {
            $warnings[] = 'gh_mismatch';
        }

        $row = $this->writer->upsert(
            studentId: $student->id,
            academicYearId: $yearId,
            workshopId: $workshop->id,
            source: WorkshopEnrollmentSource::Manual,
            status: WorkshopEnrollmentStatus::Assigned,
            notes: $notes,
            assignedBy: Auth::id(),
            protectManual: false,
        );

        return [
            'enrollment' => $row->load('workshop'),
            'warnings' => $warnings,
        ];
    }
}
