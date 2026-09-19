<?php

namespace App\Services\Export;

use App\Enums\EnrollmentStatus;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\Guardian;
use App\Models\Student;
use Carbon\Carbon;
use DateTimeInterface;

class StudentExportRowService
{
    /**
     * @param  list<int>  $ids  ordered student ids
     * @return list<array<string, string>>
     */
    public function rowsForIds(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        $students = Student::query()
            ->with([
                'profile.address',
                'enrollments.classGroup.gradeLevel',
                'enrollments.classGroup.academicYear',
                'guardians.profile',
                'workshopEnrollments.workshop',
            ])
            ->whereIn('id', $ids)
            ->get()
            ->keyBy('id');

        $rows = [];
        $n = 1;
        foreach ($ids as $id) {
            $student = $students->get($id);
            if (! $student) {
                continue;
            }
            $rows[] = $this->mapStudent($student, $n);
            $n++;
        }

        return $rows;
    }

    /**
     * @return array<string, string>
     */
    private function mapStudent(Student $student, int $np): array
    {
        $profile = $student->profile;
        [$paternal, $maternal] = $this->splitLastName($profile?->last_name);
        $firstName = trim((string) ($profile?->first_name ?? ''));
        $curp = strtoupper(trim((string) ($profile?->national_id ?? '')));
        $birthDate = $this->formatDate($profile?->birth_date) ?: $this->birthDateFromCurp($curp);
        $age = $this->ageFromDate($birthDate);
        $genderCode = $this->genderCode($profile?->gender, $curp);
        $genderLabel = $genderCode === 'M' ? 'MUJER' : ($genderCode === 'H' ? 'HOMBRE' : '');

        $enrollment = $student->enrollments->first(
            fn ($row) => $row->status === EnrollmentStatus::Active
        ) ?? $student->enrollments->first();
        $grade = (string) ($enrollment?->classGroup?->gradeLevel?->name ?? '');
        $group = (string) ($enrollment?->classGroup?->name ?? '');
        $gradeGroup = trim($grade.' '.$group);

        $yearId = $enrollment?->classGroup?->academic_year_id;
        $workshopName = '';
        if ($yearId) {
            $workshop = $student->workshopEnrollments
                ->first(function ($row) use ($yearId) {
                    return (int) $row->academic_year_id === (int) $yearId
                        && $row->status === WorkshopEnrollmentStatus::Assigned;
                });
            $workshopName = (string) ($workshop?->workshop?->name ?? '');
        }

        $address = $profile?->address;
        $guardian = $student->guardians->first();
        $gProfile = $guardian?->profile;
        [$gPaternal, $gMaternal] = $this->splitLastName($gProfile?->last_name);
        $gFirst = trim((string) ($gProfile?->first_name ?? ''));

        $fullName = trim($paternal.' '.$maternal.' '.$firstName);

        return [
            'np' => (string) $np,
            'first_name' => $firstName,
            'paternal_surname' => $paternal,
            'maternal_surname' => $maternal,
            'full_name' => $fullName,
            'curp' => $curp,
            'class_group' => $group,
            'grade_group' => $gradeGroup,
            'birth_date' => $birthDate,
            'age' => $age === null ? '' : (string) $age,
            'statistical_age' => $age === null ? '' : (string) $age,
            'gender' => $genderLabel,
            'workshop' => $workshopName,
            'street_type' => (string) ($address?->street_type ?? ''),
            'street_name' => (string) ($address?->street_name ?? ''),
            'interior_number' => (string) ($address?->apartament_number ?? $address?->unit_number ?? ''),
            'exterior_number' => (string) ($address?->house_number ?? ''),
            'neighborhood_type' => (string) ($address?->neighborhood_type ?? ''),
            'neighborhood_name' => (string) ($address?->neighborhood_name ?? ''),
            'guardian_first_name' => $gFirst,
            'guardian_paternal' => $gPaternal,
            'guardian_maternal' => $gMaternal,
            'guardian_curp' => strtoupper(trim((string) ($gProfile?->national_id ?? ''))),
            'guardian_phone' => (string) ($gProfile?->phone_number ?? ''),
            'guardian_relationship' => $this->guardianRelationship($guardian),
            'article_el_la' => $genderCode === 'M' ? 'la' : ($genderCode === 'H' ? 'el' : ''),
            'article_o_a' => $genderCode === 'M' ? 'a' : ($genderCode === 'H' ? 'o' : ''),
            'article_del_de_la' => $genderCode === 'M' ? 'de la' : ($genderCode === 'H' ? 'del' : ''),
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitLastName(?string $lastName): array
    {
        $lastName = trim((string) $lastName);
        if ($lastName === '') {
            return ['', ''];
        }
        $parts = preg_split('/\s+/', $lastName, 2) ?: [$lastName];

        return [$parts[0], $parts[1] ?? ''];
    }

    private function genderCode(?string $gender, string $curp): string
    {
        $g = strtoupper(trim((string) $gender));
        if (in_array($g, ['F', 'FEMENINO', 'MUJER'], true)) {
            return 'M';
        }
        if (in_array($g, ['M', 'H', 'MASCULINO', 'HOMBRE'], true)) {
            return 'H';
        }

        if (strlen($curp) >= 11) {
            $char = strtoupper($curp[10]);
            if ($char === 'M' || $char === 'H') {
                return $char;
            }
        }

        return '';
    }

    private function formatDate(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::parse($value)->format('Y-m-d');
        }
        if (! is_string($value) || trim($value) === '') {
            return '';
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return substr($value, 0, 10);
        }
    }

    private function birthDateFromCurp(string $curp): string
    {
        if (strlen($curp) < 10) {
            return '';
        }
        $yy = (int) substr($curp, 4, 2);
        $mm = substr($curp, 6, 2);
        $dd = substr($curp, 8, 2);
        $currentYy = (int) date('y');
        $year = $yy > $currentYy ? 1900 + $yy : 2000 + $yy;

        if (! checkdate((int) $mm, (int) $dd, $year)) {
            return '';
        }

        return sprintf('%04d-%s-%s', $year, $mm, $dd);
    }

    private function ageFromDate(string $isoDate): ?int
    {
        if ($isoDate === '') {
            return null;
        }
        try {
            return Carbon::parse($isoDate)->age;
        } catch (\Throwable) {
            return null;
        }
    }

    private function guardianRelationship(?Guardian $guardian): string
    {
        if (! $guardian) {
            return '';
        }
        $pivot = $guardian->pivot->relationship ?? null;
        if (is_string($pivot) && trim($pivot) !== '') {
            return trim($pivot);
        }

        return trim((string) ($guardian->Kinship ?? ''));
    }
}
