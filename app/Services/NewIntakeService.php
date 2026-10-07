<?php

namespace App\Services;

use App\Enums\EnrollmentStatus;
use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Address;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Guardian;
use App\Models\Profile;
use App\Models\School\ReEnrollmentPeriod;
use App\Models\Student;
use App\Models\Workshop;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class NewIntakeService
{
    public function __construct(
        private readonly WorkshopEnrollmentWriter $workshopWriter,
    ) {}

    /**
     * @return array{academic_year: ?array{id: int, label: string}, grades: list<array{id: int, name: string}>, groups: list<array{id: int, grade_level_id: int, name: string}>, workshops: list<array{id: int, name: string, code: ?string}>}
     */
    public function options(): array
    {
        $year = $this->targetYear();
        $grades = GradeLevel::query()
            ->whereIn('name', ['1°', '2°', '3°'])
            ->orderBy('id')
            ->get(['id', 'name']);

        $groups = $year
            ? ClassGroup::query()
                ->where('academic_year_id', $year->id)
                ->whereIn('grade_level_id', $grades->pluck('id'))
                ->orderBy('name')
                ->get(['id', 'grade_level_id', 'name'])
            : collect();

        $workshops = Workshop::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return [
            'academic_year' => $year ? [
                'id' => $year->id,
                'label' => trim($year->year_start.'-'.$year->year_end),
            ] : null,
            'grades' => $grades->map(fn (GradeLevel $grade) => [
                'id' => $grade->id,
                'name' => $grade->name,
            ])->values()->all(),
            'groups' => $groups->map(fn (ClassGroup $group) => [
                'id' => $group->id,
                'grade_level_id' => $group->grade_level_id,
                'name' => $group->name,
            ])->values()->all(),
            'workshops' => $workshops->map(fn (Workshop $workshop) => [
                'id' => $workshop->id,
                'name' => $workshop->name,
                'code' => $workshop->code,
            ])->values()->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{student_id: int, name: string, grade: string, group: string, workshop: string}
     */
    public function store(array $data): array
    {
        $year = $this->targetYear();
        if (! $year) {
            throw new RuntimeException('No hay un ciclo escolar destino para el nuevo ingreso.');
        }

        $grade = GradeLevel::query()->whereIn('name', ['1°', '2°', '3°'])->find($data['grade_level_id']);
        if (! $grade) {
            throw new RuntimeException('El grado debe ser 1°, 2° o 3°.');
        }

        $group = ClassGroup::query()
            ->where('id', $data['class_group_id'])
            ->where('academic_year_id', $year->id)
            ->where('grade_level_id', $grade->id)
            ->first();
        if (! $group) {
            throw new RuntimeException('El grupo no pertenece a ese grado en el ciclo '.$year->year_start.'-'.$year->year_end.'.');
        }

        $workshop = Workshop::query()->where('is_active', true)->find($data['workshop_id']);
        if (! $workshop) {
            throw new RuntimeException('El taller no está activo.');
        }

        $curp = strtoupper(trim((string) $data['curp']));
        $guardianCurp = strtoupper(trim((string) ($data['guardian_curp'] ?? '')));
        if ($guardianCurp !== '' && $curp === $guardianCurp) {
            throw new RuntimeException('La CURP del tutor debe ser distinta a la del alumno.');
        }
        if (Profile::query()->where('national_id', $curp)->exists()) {
            throw new RuntimeException('Ya existe una persona con la CURP del alumno.');
        }

        return DB::transaction(function () use ($data, $year, $grade, $group, $workshop, $curp, $guardianCurp) {
            $address = Address::query()->create([
                'street_type' => $data['street_type'],
                'street_name' => $data['street_name'],
                'house_number' => $data['house_number'],
                'unit_number' => $data['unit_number'] ?: null,
                'neighborhood_type' => $data['neighborhood_type'],
                'neighborhood_name' => $data['neighborhood_name'],
                'postal_code' => $data['postal_code'],
                'city' => $data['city'],
                'state' => $data['state'],
            ]);

            $lastName = trim($data['last_name'].' '.($data['second_last_name'] ?? ''));
            $profile = Profile::query()->create([
                'national_id' => $curp,
                'first_name' => $data['first_name'],
                'last_name' => $lastName,
                'birth_date' => $data['birth_date'],
                'gender' => $data['gender'],
                'email' => ($data['email'] ?? null) ?: null,
                'phone_number' => ($data['phone'] ?? null) ?: null,
                'address_id' => $address->id,
            ]);

            $student = Student::query()->create([
                'profile_id' => $profile->id,
                'place_of_birth' => $data['place_of_birth'],
                'previous_school' => $data['previous_school'],
                'current_average' => $data['current_average'],
                'school_voucher_folio' => $data['school_voucher_folio'] ?: null,
            ]);
            $this->linkSiblings($student, $data['sibling_ids'] ?? []);

            $guardianLast = trim($data['guardian_last_name'].' '.($data['guardian_second_last_name'] ?? ''));
            $guardianAttributes = [
                'first_name' => $data['guardian_first_name'],
                'last_name' => $guardianLast,
                'birth_date' => $data['guardian_birth_date'] ?? null,
                'gender' => $data['guardian_gender'] ?? 'O',
                'phone_number' => $data['guardian_phone'],
                'email' => $data['contact_email'],
            ];
            // Without a CURP there is no safe key to match an existing person, so it is always a new profile.
            $guardianProfile = $guardianCurp !== ''
                ? Profile::query()->firstOrCreate(['national_id' => $guardianCurp], $guardianAttributes)
                : Profile::query()->create([...$guardianAttributes, 'national_id' => null]);
            $guardian = Guardian::query()->firstOrCreate(
                ['profile_id' => $guardianProfile->id],
                ['Kinship' => $data['guardian_relationship']]
            );
            $student->guardians()->syncWithoutDetaching([
                $guardian->id => ['relationship' => $data['guardian_relationship']],
            ]);

            $enrollment = Enrollment::query()->create([
                'student_id' => $student->id,
                'class_group_id' => $group->id,
                'academic_year_id' => $year->id,
                'status' => EnrollmentStatus::Active,
                'is_new_admission' => true,
                'is_approved' => null,
                'admission_channel' => 'manual',
                'placement_status' => 'placed',
                'placed_at' => now(),
            ]);

            $this->workshopWriter->upsert(
                studentId: $student->id,
                academicYearId: $year->id,
                workshopId: $workshop->id,
                source: WorkshopEnrollmentSource::Manual,
                status: WorkshopEnrollmentStatus::Assigned,
                notes: 'Nuevo ingreso manual',
            );

            return [
                'student_id' => $student->id,
                'enrollment_id' => $enrollment->id,
                'name' => trim($profile->first_name.' '.$profile->last_name),
                'grade' => $grade->name,
                'group' => $group->name,
                'workshop' => $workshop->name,
            ];
        });
    }

    /**
     * @param  list<int>  $siblingIds
     */
    private function linkSiblings(Student $student, array $siblingIds): void
    {
        $ids = collect($siblingIds)
            ->map(fn ($id) => (int) $id)
            ->filter(fn (int $id) => $id > 0 && $id !== $student->id)
            ->unique()
            ->values();
        if ($ids->isEmpty()) {
            return;
        }

        $student->siblings()->syncWithoutDetaching($ids->all());
        foreach ($ids as $siblingId) {
            Student::query()->find($siblingId)?->siblings()->syncWithoutDetaching([$student->id]);
        }
    }

    /**
     * @return list<array{id: int, name: string, curp: string, grade: string, group: string}>
     */
    public function searchStudents(string $term): array
    {
        $term = trim($term);
        if (mb_strlen($term) < 2) {
            return [];
        }

        $like = '%'.$term.'%';

        return Student::query()
            ->with(['profile', 'currentEnrollment.classGroup.gradeLevel'])
            ->whereHas('profile', function (Builder $query) use ($like) {
                $query->where('first_name', 'like', $like)
                    ->orWhere('last_name', 'like', $like)
                    ->orWhere('national_id', 'like', $like);
            })
            ->limit(8)
            ->get()
            ->map(function (Student $student) {
                $enrollment = $student->currentEnrollment;
                $profile = $student->profile;

                return [
                    'id' => $student->id,
                    'name' => trim(($profile->first_name ?? '').' '.($profile->last_name ?? '')),
                    'curp' => strtoupper(trim((string) ($profile->national_id ?? ''))),
                    'grade' => (string) ($enrollment?->classGroup?->gradeLevel?->name ?? ''),
                    'group' => (string) ($enrollment?->classGroup?->name ?? ''),
                ];
            })
            ->values()
            ->all();
    }

    private function targetYear(): ?AcademicYear
    {
        $period = ReEnrollmentPeriod::query()
            ->where('status', 'open')
            ->orderByDesc('id')
            ->first();
        if ($period) {
            return AcademicYear::query()->find($period->to_academic_year_id);
        }

        return AcademicYear::query()->where('is_active', true)->first();
    }
}
