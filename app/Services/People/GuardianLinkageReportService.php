<?php

namespace App\Services\People;

use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Guardian;
use App\Models\Profile;
use App\Models\Student;
use Illuminate\Support\Collection;

/**
 * Enriched tutor↔student linkage for conflict UI and printable PDF.
 */
class GuardianLinkageReportService
{
    private const PROGENITOR = ['MADRE', 'PADRE'];

    private const RELATIVE = [
        'ABUELA PATERNA',
        'ABUELO PATERNO',
        'ABUELA MATERNA',
        'ABUELO MATERNO',
        'TÍA PATERNA',
        'TIA PATERNA',
        'TÍO PATERNO',
        'TIO PATERNO',
        'TÍA MATERNA',
        'TIA MATERNA',
        'TÍO MATERNO',
        'TIO MATERNO',
        'HERMANO',
        'HERMANA',
        'PRIMO',
        'PRIMA',
    ];

    public function threshold(): int
    {
        return max(0, (int) config('guardians.multi_student_warn_threshold', 2));
    }

    public function requiresConfirmation(int $linkedCount): bool
    {
        return $linkedCount > $this->threshold();
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadForProfile(Profile $profile): array
    {
        $roles = [];
        if ($profile->guardian) {
            $roles[] = 'guardian';
        }
        if ($profile->relationLoaded('teacher') ? $profile->teacher : $profile->teacher()->exists()) {
            $roles[] = 'teacher';
        }
        if ($profile->relationLoaded('staff') ? $profile->staff : $profile->staff()->exists()) {
            $roles[] = 'staff';
        }
        if ($profile->relationLoaded('student') ? $profile->student : $profile->student()->exists()) {
            $roles[] = 'student';
        }

        $students = [];
        $guardian = $profile->guardian;
        if ($guardian) {
            $guardian->loadMissing([
                'students.profile',
                'students.guardians.profile',
                'students.enrollments' => function ($q) {
                    $yearId = AcademicYear::query()->where('is_active', true)->value('id');
                    $q->where('status', EnrollmentStatus::Active)
                        ->when($yearId, fn ($eq) => $eq->where('academic_year_id', $yearId))
                        ->with(['classGroup.gradeLevel'])
                        ->latest('id');
                },
            ]);
            foreach ($guardian->students as $linked) {
                $students[] = $this->studentRow($guardian, $linked);
            }
        }

        $linkedCount = count($students);

        return [
            'profile_id' => $profile->id,
            'guardian_id' => $guardian?->id,
            'name' => $profile->fullName(),
            'curp' => $profile->national_id,
            'phone' => $profile->phone_number,
            'email' => $profile->email,
            'roles' => $roles,
            'students' => $students,
            'linked_count' => $linkedCount,
            'threshold' => $this->threshold(),
            'requires_confirmation' => $this->requiresConfirmation($linkedCount),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function payloadForGuardian(Guardian $guardian): array
    {
        $guardian->loadMissing('profile.teacher', 'profile.staff', 'profile.student', 'profile.guardian');
        $profile = $guardian->profile;
        if (! $profile) {
            return [
                'profile_id' => null,
                'guardian_id' => $guardian->id,
                'name' => '',
                'curp' => null,
                'phone' => null,
                'email' => null,
                'roles' => ['guardian'],
                'students' => [],
                'linked_count' => 0,
                'threshold' => $this->threshold(),
                'requires_confirmation' => false,
            ];
        }

        if (! $profile->relationLoaded('guardian')) {
            $profile->setRelation('guardian', $guardian);
        }

        return $this->payloadForProfile($profile);
    }

    public function classifyRelationship(?string $relationship): string
    {
        $value = mb_strtoupper(trim((string) $relationship));
        if ($value === '') {
            return 'otro';
        }
        if (in_array($value, self::PROGENITOR, true)) {
            return 'progenitor';
        }
        if (in_array($value, self::RELATIVE, true)) {
            return 'pariente';
        }

        return 'otro';
    }

    /**
     * @return array{
     *   student_id: int,
     *   name: string,
     *   curp: string|null,
     *   grade_group: string,
     *   role: 'primary'|'secondary'|'linked',
     *   relationship: string|null,
     *   relationship_kind: string,
     *   other_guardians: list<array{id: int, name: string, relationship: string|null, role: string}>
     * }
     */
    private function studentRow(Guardian $holder, Student $linked): array
    {
        $isPrimary = (bool) ($linked->pivot?->is_primary ?? false);
        $isSecondary = (bool) ($linked->pivot?->is_secondary ?? false);
        $role = $isPrimary ? 'primary' : ($isSecondary ? 'secondary' : 'linked');
        $relationship = $linked->pivot?->relationship;

        $others = [];
        foreach ($linked->guardians as $other) {
            if ((int) $other->id === (int) $holder->id) {
                continue;
            }
            $otherPrimary = (bool) ($other->pivot?->is_primary ?? false);
            $otherSecondary = (bool) ($other->pivot?->is_secondary ?? false);
            $others[] = [
                'id' => (int) $other->id,
                'name' => $other->profile?->fullName() ?? '',
                'relationship' => $other->pivot?->relationship,
                'role' => $otherPrimary ? 'primary' : ($otherSecondary ? 'secondary' : 'linked'),
            ];
        }

        return [
            'id' => (int) $linked->id,
            'student_id' => (int) $linked->id,
            'name' => $linked->profile?->fullName() ?? '',
            'curp' => $linked->profile?->national_id,
            'grade_group' => $this->gradeGroup($linked),
            'role' => $role,
            'relationship' => $relationship,
            'relationship_kind' => $this->classifyRelationship($relationship),
            'other_guardians' => $others,
        ];
    }

    private function gradeGroup(Student $student): string
    {
        $enrollment = $student->relationLoaded('enrollments')
            ? $student->enrollments->first()
            : null;
        $group = $enrollment?->classGroup;

        return trim(($group?->gradeLevel?->name ?? '').' '.($group?->name ?? ''));
    }
}
