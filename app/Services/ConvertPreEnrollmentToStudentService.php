<?php

namespace App\Services;

use App\Enums\DocumentsStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\PreEnrollmentStatus;
use App\Exceptions\AdmissionConversionException;
use App\Models\AcademicYear;
use App\Models\Address;
use App\Models\AdmissionIntakeSetting;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Guardian;
use App\Models\PreEnrollment;
use App\Models\Profile;
use App\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

class ConvertPreEnrollmentToStudentService
{
    /**
     * @param  array{
     *   academic_year_id?: int|null,
     *   class_group_id?: int|null,
     *   channel?: string,
     *   force_incomplete_docs?: bool,
     *   force_incomplete_data?: bool,
     *   force_without_payment?: bool,
     *   converted_by?: int|null,
     * }  $options
     * @return array{
     *   student: Student,
     *   enrollment: Enrollment,
     *   exception_flags: list<string>,
     *   replayed: bool
     * }
     */
    public function convert(PreEnrollment $preEnrollment, array $options = []): array
    {
        try {
            return DB::transaction(function () use ($preEnrollment, $options) {
                $locked = PreEnrollment::whereKey($preEnrollment->id)->lockForUpdate()->first();
                if (! $locked) {
                    throw new AdmissionConversionException(
                        'pre_enrollment_not_found',
                        __('admissions.to_student.pre_enrollment_not_found'),
                        404,
                    );
                }

                if ($locked->converted_student_id) {
                    return $this->replayConversion($locked);
                }

                $settings = AdmissionIntakeSetting::current();
                $channel = ($options['channel'] ?? 'campaign') === 'late' ? 'late' : 'campaign';
                $exceptionFlags = [];

                if ($locked->status === PreEnrollmentStatus::REJECTED) {
                    throw new AdmissionConversionException(
                        'rejected_application',
                        __('admissions.to_student.rejected_application'),
                    );
                }

                if ($locked->status !== PreEnrollmentStatus::IN_REVIEW) {
                    throw new AdmissionConversionException(
                        'review_required',
                        __('admissions.to_student.review_required'),
                    );
                }

                if ($channel === 'late' && ! $settings->late_intake_enabled) {
                    throw new AdmissionConversionException(
                        'late_intake_disabled',
                        __('admissions.to_student.late_intake_disabled'),
                    );
                }

                // Documents gate
                if ($locked->documents_status !== DocumentsStatus::COMPLETE) {
                    $forceDocs = (bool) ($options['force_incomplete_docs'] ?? false);
                    if ($forceDocs && $settings->allow_convert_without_complete_docs) {
                        $exceptionFlags[] = 'docs_incomplete';
                    } else {
                        throw new AdmissionConversionException(
                            'documents_incomplete',
                            __('admissions.to_student.documents_incomplete'),
                        );
                    }
                }

                // Payment gate
                if ($locked->payment_status !== PaymentStatus::VALIDATED) {
                    $forcePay = (bool) ($options['force_without_payment'] ?? false);
                    if ($forcePay && $settings->allow_convert_without_payment) {
                        $exceptionFlags[] = 'payment_skipped';
                    } else {
                        throw new AdmissionConversionException(
                            'payment_not_validated',
                            __('admissions.to_student.payment_not_validated'),
                        );
                    }
                }

                // Soft "complete data" check (CURP + names + school)
                $dataComplete = $this->hasCompleteIdentityData($locked);
                if (! $dataComplete) {
                    $forceData = (bool) ($options['force_incomplete_data'] ?? false);
                    if ($forceData && $settings->allow_convert_without_complete_data) {
                        $exceptionFlags[] = 'data_incomplete';
                    } else {
                        throw new AdmissionConversionException(
                            'data_incomplete',
                            __('admissions.to_student.data_incomplete'),
                        );
                    }
                }

                if ($settings->require_exam_before_convert && $locked->admission_exam_score === null) {
                    throw new AdmissionConversionException(
                        'exam_required',
                        __('admissions.to_student.exam_required'),
                    );
                }

                $aspirantCurp = strtoupper(trim((string) $locked->curp));
                if ($aspirantCurp === '') {
                    throw new AdmissionConversionException(
                        'curp_required',
                        __('admissions.to_student.curp_required'),
                    );
                }
                if (Profile::where('national_id', $aspirantCurp)->exists()) {
                    throw new AdmissionConversionException(
                        'curp_conflict',
                        __('admissions.to_student.student_profile_exists'),
                        409,
                    );
                }

                $academicYearId = $options['academic_year_id'] ?? null;
                $classGroupId = $options['class_group_id'] ?? null;

                $academicYear = $academicYearId
                    ? AcademicYear::find($academicYearId)
                    : AcademicYear::where('is_active', true)->first();

                if (! $academicYear) {
                    throw new AdmissionConversionException(
                        $academicYearId ? 'academic_year_not_found' : 'no_academic_year',
                        $academicYearId
                            ? __('admissions.to_student.academic_year_not_found')
                            : __('admissions.to_student.no_academic_year'),
                    );
                }

                if ($channel === 'late' && $settings->late_requires_manual_group && ! $classGroupId) {
                    throw new AdmissionConversionException(
                        'late_group_required',
                        __('admissions.to_student.late_group_required'),
                    );
                }

                $classGroup = null;
                if ($classGroupId) {
                    $classGroup = ClassGroup::with('gradeLevel')
                        ->whereKey($classGroupId)
                        ->first();
                    if (! $classGroup) {
                        throw new AdmissionConversionException(
                            'class_group_not_found',
                            __('admissions.to_student.class_group_not_found'),
                        );
                    }
                    if ((int) $classGroup->academic_year_id !== (int) $academicYear->id) {
                        throw new AdmissionConversionException(
                            'group_not_in_academic_year',
                            __('admissions.to_student.group_not_in_academic_year'),
                        );
                    }
                }

                if (! $classGroup) {
                    $firstGrade = GradeLevel::where('name', '1°')->first();
                    if (! $firstGrade) {
                        throw new AdmissionConversionException(
                            'first_grade_not_found',
                            __('admissions.to_student.first_grade_not_found'),
                        );
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
                    throw new AdmissionConversionException(
                        'default_group_not_found',
                        __('admissions.to_student.default_group_not_found'),
                    );
                }

                $address = Address::create([
                    'street_type' => $locked->street_type,
                    'street_name' => $locked->street_name,
                    'house_number' => $locked->house_number,
                    'unit_number' => $locked->unit_number,
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
                    ['national_id' => strtoupper(trim((string) $locked->guardian_curp))],
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
                    throw new AdmissionConversionException(
                        'already_enrolled',
                        __('admissions.to_student.already_enrolled'),
                        409,
                    );
                }

                $placementStatus = $channel === 'late' ? 'placed' : 'provisional';

                $enrollment = Enrollment::create([
                    'student_id' => $student->id,
                    'class_group_id' => $classGroup->id,
                    'academic_year_id' => $academicYear->id,
                    'status' => EnrollmentStatus::Active,
                    'is_new_admission' => true,
                    'is_approved' => null,
                    'admission_channel' => $channel,
                    'placement_status' => $placementStatus,
                    'convert_exception_flags' => $exceptionFlags ?: null,
                    'placed_at' => $placementStatus === 'placed' ? now() : null,
                ]);

                $locked->update([
                    'converted_student_id' => $student->id,
                    'converted_enrollment_id' => $enrollment->id,
                    'status' => PreEnrollmentStatus::APPROVED,
                    'converted_by' => $options['converted_by'] ?? null,
                    'converted_at' => now(),
                    'conversion_options' => [
                        'academic_year_id' => $academicYear->id,
                        'class_group_id' => $classGroup->id,
                        'channel' => $channel,
                        'force_incomplete_docs' => (bool) ($options['force_incomplete_docs'] ?? false),
                        'force_incomplete_data' => (bool) ($options['force_incomplete_data'] ?? false),
                        'force_without_payment' => (bool) ($options['force_without_payment'] ?? false),
                    ],
                    'conversion_policy_snapshot' => $settings->toApiArray(),
                ]);

                return [
                    'student' => $student->fresh(),
                    'enrollment' => $enrollment->fresh(),
                    'exception_flags' => $exceptionFlags,
                    'replayed' => false,
                ];
            });
        } catch (AdmissionConversionException $exception) {
            throw $exception;
        } catch (QueryException $exception) {
            throw $this->normalizeQueryException($exception);
        } catch (Throwable $exception) {
            throw new AdmissionConversionException(
                'conversion_failed',
                __('admissions.to_student.conversion_failed'),
                500,
                $exception,
            );
        }
    }

    /**
     * Return the original conversion result after a client retry or a
     * concurrent request that completed while waiting for the row lock.
     *
     * Existing converted records predate converted_enrollment_id, so their
     * first admission enrollment is resolved once and persisted lazily.
     *
     * @return array{
     *   student: Student,
     *   enrollment: Enrollment,
     *   exception_flags: list<string>,
     *   replayed: bool
     * }
     */
    private function replayConversion(PreEnrollment $preEnrollment): array
    {
        $student = Student::find($preEnrollment->converted_student_id);
        $enrollment = $preEnrollment->converted_enrollment_id
            ? Enrollment::find($preEnrollment->converted_enrollment_id)
            : Enrollment::query()
                ->where('student_id', $preEnrollment->converted_student_id)
                ->where('is_new_admission', true)
                ->oldest('id')
                ->first();

        if (! $enrollment) {
            $enrollment = Enrollment::query()
                ->where('student_id', $preEnrollment->converted_student_id)
                ->oldest('id')
                ->first();
        }

        if (! $student || ! $enrollment) {
            throw new AdmissionConversionException(
                'conversion_state_incomplete',
                __('admissions.to_student.conversion_state_incomplete'),
                409,
            );
        }

        if (! $preEnrollment->converted_enrollment_id) {
            $preEnrollment->update(['converted_enrollment_id' => $enrollment->id]);
        }

        return [
            'student' => $student,
            'enrollment' => $enrollment,
            'exception_flags' => $enrollment->convert_exception_flags ?? [],
            'replayed' => true,
        ];
    }

    private function normalizeQueryException(QueryException $exception): AdmissionConversionException
    {
        $driverCode = (int) ($exception->errorInfo[1] ?? 0);
        $message = strtolower($exception->getMessage());
        $isDuplicate = $exception->getCode() === '23000'
            && in_array($driverCode, [19, 1062], true);

        if ($isDuplicate && str_contains($message, 'profiles_national_id_unique')) {
            return new AdmissionConversionException(
                'curp_conflict',
                __('admissions.to_student.student_profile_exists'),
                409,
                $exception,
            );
        }

        if ($isDuplicate && str_contains($message, 'enrollments_student_year_unique')) {
            return new AdmissionConversionException(
                'already_enrolled',
                __('admissions.to_student.already_enrolled'),
                409,
                $exception,
            );
        }

        if ($isDuplicate) {
            return new AdmissionConversionException(
                'conversion_conflict',
                __('admissions.to_student.conversion_conflict'),
                409,
                $exception,
            );
        }

        return new AdmissionConversionException(
            'persistence_error',
            __('admissions.to_student.persistence_error'),
            500,
            $exception,
        );
    }

    private function hasCompleteIdentityData(PreEnrollment $pre): bool
    {
        return trim((string) $pre->first_name) !== ''
            && trim((string) $pre->last_name) !== ''
            && trim((string) $pre->curp) !== ''
            && trim((string) $pre->previous_school) !== '';
    }
}
