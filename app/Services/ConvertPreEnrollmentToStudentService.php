<?php

namespace App\Services;

use App\Enums\DocumentsStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\PaymentStatus;
use App\Models\AcademicYear;
use App\Models\Address;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Guardian;
use App\Models\PreEnrollment;
use App\Models\Profile;
use App\Models\Student;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ConvertPreEnrollmentToStudentService
{
    /**
     * Creates Profile + Student + Guardian linked and an active enrollment (1st grade, provisional group).
     * The group can be changed later when you define assignment by exam.
     * Crea Perfil + Estudiante + Tutor enlazados y una matrícula activa (1°, grupo provisional).
     * El grupo puede cambiar luego cuando definas asignación por examen.
     *
     * @return array{student: Student, enrollment: Enrollment}
     */
    public function convert(
        PreEnrollment $preEnrollment,
        ?int $academicYearId = null,
        ?int $classGroupId = null,
    ): array {
        return DB::transaction(function () use ($preEnrollment, $academicYearId, $classGroupId) {

            $locked = PreEnrollment::whereKey($preEnrollment->id)->lockForUpdate()->firstOrFail();

            if ($locked->converted_student_id) {
                throw new RuntimeException(__('admissions.to_student.already_converted'));
            }

            if ($locked->documents_status !== DocumentsStatus::COMPLETE) {
                throw new RuntimeException(__('admissions.to_student.documents_incomplete'));
            }

            if ($locked->payment_status !== PaymentStatus::VALIDATED) {
                throw new RuntimeException(__('admissions.to_student.payment_not_validated'));
            }

            if ($locked->status === \App\Enums\PreEnrollmentStatus::REJECTED) {
                throw new RuntimeException(__('admissions.to_student.rejected_application'));
            }

            $aspirantCurp = strtoupper(trim($locked->curp));

            if (Profile::where('national_id', $aspirantCurp)->exists()) {
                throw new RuntimeException(__('admissions.to_student.student_profile_exists'));
            }

            $academicYear = $academicYearId
                ? AcademicYear::findOrFail($academicYearId)
                : AcademicYear::where('is_active', true)->first();

            if (! $academicYear) {
                throw new RuntimeException(__('admissions.to_student.no_academic_year'));
            }

            $classGroup = null;
            if ($classGroupId) {
                $classGroup = ClassGroup::with('gradeLevel')
                    ->whereKey($classGroupId)
                    ->firstOrFail();
                if ((int) $classGroup->academic_year_id !== (int) $academicYear->id) {
                    throw new RuntimeException(__('admissions.to_student.group_not_in_academic_year'));
                }
            }

            if (! $classGroup) {
                $firstGrade = GradeLevel::where('name', '1°')->first();
                if (! $firstGrade) {
                    throw new RuntimeException(__('admissions.to_student.first_grade_not_found'));
                }

                $classGroup = ClassGroup::query()
                    ->where('academic_year_id', $academicYear->id)
                    ->where('grade_level_id', $firstGrade->id)
                    ->where('name', 'A')
                    ->first();

                if (! $classGroup) {
                    $classGroup = ClassGroup::query()
                        ->where('academic_year_id', $academicYear->id)
                        ->where('grade_level_id', $firstGrade->id)
                        ->orderBy('name')
                        ->first();
                }
            }

            if (! $classGroup) {
                throw new RuntimeException(__('admissions.to_student.default_group_not_found'));
            }

            $address = Address::create([
                'street_type' => $locked->street_type,
                'street_name' => $locked->street_name,
                'house_number' => $locked->house_number,
                'apartament_number' => $locked->unit_number,
                'neighborhood_type' => $locked->neighborhood_type,
                'neighborhood_name' => $locked->neighborhood_name,
                'postal_code' => $locked->postal_code,
                'city' => $locked->city,
                'state' => $locked->state,
            ]);

            $studentLastName = trim(implode(' ', array_filter([
                $locked->last_name,
                $locked->second_last_name,
            ])));

            $studentProfile = Profile::create([
                'national_id' => $aspirantCurp,
                'first_name' => $locked->first_name,
                'last_name' => $studentLastName !== '' ? $studentLastName : $locked->last_name,
                'birth_date' => $locked->birth_date,
                'gender' => $locked->gender,
                'email' => $locked->student_email,
                'phone_number' => $locked->phone,
                'address_id' => $address->id,
            ]);

            $student = Student::create([
                'profile_id' => $studentProfile->id,
            ]);

            $guardianLast = trim(implode(' ', array_filter([
                $locked->guardian_last_name,
                $locked->guardian_second_last_name,
            ])));

            $guardianProfile = Profile::firstOrCreate(
                ['national_id' => strtoupper(trim($locked->guardian_curp))],
                [
                    'first_name' => $locked->guardian_first_name,
                    'last_name' => $guardianLast !== '' ? $guardianLast : $locked->guardian_last_name,
                    'gender' => 'O',
                    'birth_date' => null,
                    'email' => $locked->contact_email,
                    'phone_number' => $locked->guardian_phone,
                    'phone_second_number' => null,
                ]
            );

            $guardian = Guardian::firstOrCreate(
                ['profile_id' => $guardianProfile->id],
                ['Kinship' => $locked->guardian_relationship ?: 'TUTOR']
            );

            $student->guardians()->syncWithoutDetaching([
                $guardian->id => ['relationship' => $locked->guardian_relationship],
            ]);

            if (Enrollment::query()
                ->where('student_id', $student->id)
                ->where('academic_year_id', $academicYear->id)
                ->where('status', EnrollmentStatus::Active)
                ->exists()) {
                throw new RuntimeException(__('admissions.to_student.already_enrolled'));
            }

            $enrollment = Enrollment::create([
                'student_id' => $student->id,
                'class_group_id' => $classGroup->id,
                'academic_year_id' => $academicYear->id,
                'status' => EnrollmentStatus::Active,
                'is_new_admission' => true,
                'is_approved' => null,
            ]);

            $locked->update([
                'converted_student_id' => $student->id,
                'status' => \App\Enums\PreEnrollmentStatus::APPROVED,
            ]);

            return [
                'student' => $student->fresh(),
                'enrollment' => $enrollment->fresh(),
            ];
        });
    }
}
