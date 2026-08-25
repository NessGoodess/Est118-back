<?php

namespace Tests\Feature\Admission;

use App\Enums\DocumentsStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\PreEnrollmentStatus;
use App\Exceptions\AdmissionConversionException;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\PreEnrollment;
use App\Models\Profile;
use App\Models\Student;
use App\Services\ConvertPreEnrollmentToStudentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConvertPreEnrollmentToStudentServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_returns_the_original_conversion_as_replayed(): void
    {
        $preEnrollment = PreEnrollment::factory()->create();
        [$student, $enrollment] = $this->createStudentAndEnrollment();

        $preEnrollment->update([
            'converted_student_id' => $student->id,
            'converted_enrollment_id' => $enrollment->id,
            'status' => PreEnrollmentStatus::APPROVED,
        ]);

        $result = app(ConvertPreEnrollmentToStudentService::class)
            ->convert($preEnrollment);

        $this->assertTrue($result['replayed']);
        $this->assertSame($student->id, $result['student']->id);
        $this->assertSame($enrollment->id, $result['enrollment']->id);
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_existing_student_curp_returns_a_stable_conflict(): void
    {
        $preEnrollment = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
        ]);

        Profile::create([
            'national_id' => $preEnrollment->curp,
            'first_name' => 'Existing',
            'last_name' => 'Student',
            'gender' => 'O',
        ]);

        try {
            app(ConvertPreEnrollmentToStudentService::class)
                ->convert($preEnrollment);
            $this->fail('Expected a CURP conflict.');
        } catch (AdmissionConversionException $exception) {
            $this->assertSame('curp_conflict', $exception->errorCode);
            $this->assertSame(409, $exception->httpStatus);
        }

        $this->assertDatabaseCount('students', 0);
        $this->assertNull($preEnrollment->fresh()->converted_student_id);
    }

    public function test_missing_academic_year_is_normalized(): void
    {
        $preEnrollment = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
        ]);

        try {
            app(ConvertPreEnrollmentToStudentService::class)
                ->convert($preEnrollment, ['academic_year_id' => 999999]);
            $this->fail('Expected a missing academic year error.');
        } catch (AdmissionConversionException $exception) {
            $this->assertSame('academic_year_not_found', $exception->errorCode);
            $this->assertSame(422, $exception->httpStatus);
        }

        $this->assertDatabaseCount('students', 0);
        $this->assertNull($preEnrollment->fresh()->converted_student_id);
    }

    /**
     * @return array{Student, Enrollment}
     */
    private function createStudentAndEnrollment(): array
    {
        $academicYear = AcademicYear::factory()->create();
        $grade = GradeLevel::create(['name' => '1°']);
        $group = ClassGroup::create([
            'academic_year_id' => $academicYear->id,
            'grade_level_id' => $grade->id,
            'name' => 'A',
        ]);
        $profile = Profile::create([
            'national_id' => 'TEST000000HOAXXX00',
            'first_name' => 'Replay',
            'last_name' => 'Student',
            'gender' => 'O',
        ]);
        $student = Student::create(['profile_id' => $profile->id]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'class_group_id' => $group->id,
            'academic_year_id' => $academicYear->id,
            'status' => EnrollmentStatus::Active,
            'is_new_admission' => true,
        ]);

        return [$student, $enrollment];
    }
}
