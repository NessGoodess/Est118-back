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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AdmissionConcurrencyAndCurpTest extends TestCase
{
    use RefreshDatabase;

    public function test_curp_is_normalized_on_create(): void
    {
        $pre = PreEnrollment::factory()->create([
            'curp' => ' pelj010101hocrpn09 ',
            'guardian_curp' => ' logm800101mocrrr09 ',
        ]);

        $this->assertSame('PELJ010101HOCRPN09', $pre->fresh()->curp);
        $this->assertSame('LOGM800101MOCRRR09', $pre->fresh()->guardian_curp);
        $this->assertSame('PELJ010101HOCRPN09', $pre->fresh()->curp_normalized);
    }

    public function test_normalized_curp_unique_rejects_case_variant(): void
    {
        PreEnrollment::factory()->create([
            'curp' => 'PELJ010101HOCRPN09',
        ]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        PreEnrollment::factory()->create([
            'curp' => 'pelj010101hocrpn09',
        ]);
    }

    public function test_sequential_convert_of_same_pre_enrollment_replays(): void
    {
        [$year] = $this->seedYearWithGroup();
        $pre = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
        ]);

        $service = app(ConvertPreEnrollmentToStudentService::class);

        $first = $service->convert($pre, ['academic_year_id' => $year->id]);
        $second = $service->convert($pre->fresh(), ['academic_year_id' => $year->id]);

        $this->assertFalse($first['replayed']);
        $this->assertTrue($second['replayed']);
        $this->assertSame($first['student']->id, $second['student']->id);
        $this->assertSame($first['enrollment']->id, $second['enrollment']->id);
        $this->assertDatabaseCount('students', 1);
    }

    public function test_convert_under_row_lock_does_not_duplicate(): void
    {
        [$year] = $this->seedYearWithGroup();
        $pre = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
        ]);

        $service = app(ConvertPreEnrollmentToStudentService::class);

        // Simulate a concurrent winner completing while another request holds work.
        $resultA = null;
        $resultB = null;

        DB::transaction(function () use ($service, $pre, $year, &$resultA, &$resultB) {
            $locked = PreEnrollment::whereKey($pre->id)->lockForUpdate()->first();
            $resultA = $service->convert($locked, ['academic_year_id' => $year->id]);
            $resultB = $service->convert($locked->fresh(), ['academic_year_id' => $year->id]);
        });

        $this->assertFalse($resultA['replayed']);
        $this->assertTrue($resultB['replayed']);
        $this->assertDatabaseCount('students', 1);
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_profile_national_id_conflict_is_stable_409(): void
    {
        [$year] = $this->seedYearWithGroup();
        $pre = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::COMPLETE,
            'payment_status' => PaymentStatus::VALIDATED,
            'curp' => 'PELJ010101HOCRPN09',
        ]);

        Profile::create([
            'national_id' => 'pelj010101hocrpn09',
            'first_name' => 'Existing',
            'last_name' => 'Student',
            'gender' => 'O',
        ]);

        try {
            app(ConvertPreEnrollmentToStudentService::class)
                ->convert($pre, ['academic_year_id' => $year->id]);
            $this->fail('Expected CURP conflict.');
        } catch (AdmissionConversionException $exception) {
            $this->assertSame('curp_conflict', $exception->errorCode);
            $this->assertSame(409, $exception->httpStatus);
        }

        $this->assertDatabaseCount('students', 0);
    }

    /**
     * @return array{0: AcademicYear, 1: ClassGroup}
     */
    private function seedYearWithGroup(): array
    {
        $year = AcademicYear::factory()->create(['is_active' => true]);
        $grade = GradeLevel::create(['name' => '1°']);
        $group = ClassGroup::create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'name' => 'A',
        ]);

        return [$year, $group];
    }
}
