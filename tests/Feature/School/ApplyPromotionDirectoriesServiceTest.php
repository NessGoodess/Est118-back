<?php

namespace Tests\Feature\School;

use App\Console\Commands\temporal\ApplyPromotionDirectoriesService;
use App\Enums\EnrollmentStatus;
use App\Enums\PromotionResult;
use App\Enums\ReEnrollmentEventAction;
use App\Enums\ReEnrollmentPeriodStatus;
use App\Enums\ReEnrollmentProcessStep;
use App\Enums\ReEnrollmentValidationStatus;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Guardian;
use App\Models\Profile;
use App\Models\School\ReEnrollmentApplication;
use App\Models\School\ReEnrollmentPeriod;
use App\Models\Student;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ApplyPromotionDirectoriesServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $second = '';

    private string $third = '';

    protected function tearDown(): void
    {
        foreach ([$this->second, $this->third] as $path) {
            if ($path !== '' && is_file($path)) {
                unlink($path);
            }
        }

        parent::tearDown();
    }

    public function test_directories_promote_into_the_open_period_and_keep_history(): void
    {
        $from = AcademicYear::factory()->create(['is_active' => true, 'year_start' => '2025', 'year_end' => '2026']);
        $to = AcademicYear::factory()->create(['is_active' => false, 'year_start' => '2026', 'year_end' => '2027']);
        $first = GradeLevel::query()->create(['name' => '1°']);
        $secondGrade = GradeLevel::query()->create(['name' => '2°']);
        $thirdGrade = GradeLevel::query()->create(['name' => '3°']);
        $originGroup = ClassGroup::query()->create([
            'academic_year_id' => $from->id,
            'grade_level_id' => $first->id,
            'name' => 'A',
        ]);
        $secondOrigin = ClassGroup::query()->create([
            'academic_year_id' => $from->id,
            'grade_level_id' => $secondGrade->id,
            'name' => 'C',
        ]);
        $thirdOrigin = ClassGroup::query()->create([
            'academic_year_id' => $from->id,
            'grade_level_id' => $thirdGrade->id,
            'name' => 'A',
        ]);
        Workshop::query()->updateOrCreate(
            ['code' => 'INFORMATICA'],
            ['name' => 'Informática', 'is_active' => true, 'is_internal' => false]
        );

        $profile = Profile::query()->create([
            'national_id' => 'BECA131211HOCTRRA7',
            'first_name' => 'ARTURO',
            'last_name' => 'VIEJO',
            'gender' => 'M',
            'birth_date' => '2013-12-11',
        ]);
        $student = Student::query()->create(['profile_id' => $profile->id]);
        $origin = Enrollment::query()->create([
            'student_id' => $student->id,
            'class_group_id' => $originGroup->id,
            'academic_year_id' => $from->id,
            'status' => EnrollmentStatus::Active,
            'is_approved' => true,
        ]);
        $user = User::factory()->create();
        $period = ReEnrollmentPeriod::query()->create([
            'name' => '2026-2027',
            'from_academic_year_id' => $from->id,
            'to_academic_year_id' => $to->id,
            'status' => ReEnrollmentPeriodStatus::OPEN,
            'current_step' => ReEnrollmentProcessStep::VALIDATION,
            'keep_current_groups' => true,
            'created_by' => $user->id,
        ]);
        ReEnrollmentApplication::query()->create([
            're_enrollment_period_id' => $period->id,
            'enrollment_id' => $origin->id,
            'student_id' => $student->id,
            'status' => ReEnrollmentValidationStatus::VALIDATED,
            'passed_cycle' => true,
        ]);
        $graduate = $this->enroll($from, $period, $thirdOrigin, 'EGRE110101HOCRRNA1', 'LAURA', true);
        $repeater = $this->enroll($from, $period, $originGroup, 'REPR130101HOCRRNA2', 'RAUL', null);
        $missing = $this->enroll($from, $period, $secondOrigin, 'APPR120101HOCRRNA3', 'GLORIA', true);
        $demo = $this->enroll($from, $period, $secondOrigin, 'EADO130920HOCLNS03', 'Ejemplo Alumno', null);
        $demo->student->profile->update(['last_name' => 'Dos']);
        $demoOne = $this->enroll($from, $period, $secondOrigin, 'EAUO120615HOCLNS02', 'Ejemplo Alumno', null);
        $demoOne->student->profile->update(['last_name' => 'Uno']);
        $tutorProfile = Profile::query()->create([
            'national_id' => 'EJTU850101HOCLNS01',
            'first_name' => 'Ejemplo',
            'last_name' => 'Tutor',
            'gender' => 'M',
            'birth_date' => '1985-01-01',
        ]);
        $tutor = Guardian::query()->create(['profile_id' => $tutorProfile->id]);
        $demo->student->guardians()->attach($tutor->id);
        $demoOne->student->guardians()->attach($tutor->id);
        DB::table('general_attendances')->insert([
            'student_id' => $demo->student_id,
            'academic_year_id' => $from->id,
            'date' => '2026-09-01',
            'source' => 'nfc',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('recent_readings')->insert([
            'student_id' => $demo->student_id,
            'read_at' => now(),
            'event' => 'entry',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->second = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dir-2.xlsx';
        $this->third = sys_get_temp_dir().DIRECTORY_SEPARATOR.'dir-3.xlsx';
        $this->writeSecond($this->second);
        $this->writeThird($this->third);

        $summary = app(ApplyPromotionDirectoriesService::class)->apply($this->second, $this->third, dryRun: false);

        $this->assertSame(2, $summary['applied']);
        $this->assertCount(1, $summary['graduated']);
        $this->assertSame([], $summary['retained']);
        $this->assertSame('RAUL PRUEBA', $summary['needs_review'][0]['name']);
        $this->assertSame('GLORIA PRUEBA', $summary['needs_review'][1]['name']);
        $this->assertSame('Ejemplo Alumno Dos', $summary['removed_demo'][0]['name']);
        $this->assertSame('Ejemplo Alumno Uno', $summary['removed_demo'][1]['name']);
        $this->assertSame('Ejemplo Tutor', $summary['removed_demo'][2]['name']);
        $this->assertNull(Profile::query()->where('national_id', 'EADO130920HOCLNS03')->first());
        $this->assertNull(Profile::query()->where('national_id', 'EAUO120615HOCLNS02')->first());
        $this->assertNull(Profile::query()->where('national_id', 'EJTU850101HOCLNS01')->first());
        $this->assertSame(0, DB::table('general_attendances')->where('student_id', $demo->student_id)->count());
        $this->assertSame(0, DB::table('recent_readings')->where('student_id', $demo->student_id)->count());
        $this->assertSame(0, DB::table('re_enrollment_applications')->whereIn('student_id', [$demo->student_id, $demoOne->student_id])->count());
        $this->assertNull(Guardian::query()->find($tutor->id));
        $this->assertSame('SIN REGISTRO', $summary['manual_adds'][0]['name']);
        $this->assertNotEmpty($summary['age_mismatches']);
        $this->assertSame([], $summary['errors']);

        $origin->refresh();
        $this->assertSame(EnrollmentStatus::Completed, $origin->status);
        $this->assertSame(PromotionResult::PROMOTED, $origin->promotion_result);

        $destination = Enrollment::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', $to->id)
            ->first();
        $this->assertNotNull($destination);
        $this->assertSame($secondGrade->id, $destination->classGroup->grade_level_id);
        $this->assertSame('B', $destination->classGroup->name);
        $this->assertSame(EnrollmentStatus::Active, $destination->status);
        $this->assertNull(WorkshopEnrollment::query()->where('student_id', $student->id)->first());

        $period->refresh();
        $this->assertNotNull($period->promotion_executed_at);
        $this->assertSame(ReEnrollmentProcessStep::COMPLETED, $period->current_step);
        $this->assertTrue($period->events()->where('action', ReEnrollmentEventAction::PROMOTION_EXECUTED)->exists());
        $this->assertSame('ARTURO ALEJANDRO', $profile->fresh()->first_name);
        $this->assertSame('2013-12-11', substr((string) $profile->fresh()->birth_date, 0, 10));

        $graduate->refresh();
        $this->assertSame(EnrollmentStatus::Completed, $graduate->status);
        $this->assertSame(PromotionResult::GRADUATED, $graduate->promotion_result);
        $this->assertNull(Enrollment::query()->where('student_id', $graduate->student_id)->where('academic_year_id', $to->id)->first());

        $repeater->refresh();
        $this->assertSame(EnrollmentStatus::Active, $repeater->status);
        $this->assertNull($repeater->promotion_result);
        $this->assertNull($repeater->is_approved);
        $this->assertNull(Enrollment::query()->where('student_id', $repeater->student_id)->where('academic_year_id', $to->id)->first());

        $missing->refresh();
        $this->assertSame(EnrollmentStatus::Active, $missing->status);
        $this->assertNull(Enrollment::query()->where('student_id', $missing->student_id)->where('academic_year_id', $to->id)->first());

        $again = app(ApplyPromotionDirectoriesService::class)->apply($this->second, $this->third, dryRun: false);
        $this->assertSame(2, $again['applied']);
        $this->assertSame(1, Enrollment::query()->where('academic_year_id', $to->id)->count());
        $this->assertSame(1, $period->events()->where('action', ReEnrollmentEventAction::PROMOTION_EXECUTED)->count());
    }

    private function enroll(AcademicYear $year, ReEnrollmentPeriod $period, ClassGroup $group, string $curp, string $firstName, ?bool $approved): Enrollment
    {
        $profile = Profile::query()->create([
            'national_id' => $curp,
            'first_name' => $firstName,
            'last_name' => 'PRUEBA',
            'gender' => 'M',
            'birth_date' => '2013-01-01',
        ]);
        $student = Student::query()->create(['profile_id' => $profile->id]);
        $enrollment = Enrollment::query()->create([
            'student_id' => $student->id,
            'class_group_id' => $group->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
            'is_approved' => $approved,
        ]);
        ReEnrollmentApplication::query()->create([
            're_enrollment_period_id' => $period->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $student->id,
            'status' => ReEnrollmentValidationStatus::VALIDATED,
            'passed_cycle' => $approved,
        ]);

        return $enrollment;
    }

    private function writeSecond(string $path): void
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('DIRECTORIO');
        $sheet->setCellValue('B5', 'ARTURO ALEJANDRO');
        $sheet->setCellValue('C5', 'BETANZOS');
        $sheet->setCellValue('D5', 'CRUZ');
        $sheet->setCellValue('F5', 'BECA131211HOCTRRA7');
        $sheet->setCellValue('G5', 'B');
        $sheet->setCellValue('H5', '14/09/2019');
        $sheet->setCellValue('I5', '15');
        $sheet->setCellValue('J5', 'HOMBRE');
        (new Xlsx($book))->save($path);
    }

    private function writeThird(string $path): void
    {
        $book = new Spreadsheet();
        $general = $book->getActiveSheet();
        $general->setTitle('DATOS GENERALES');
        $general->setCellValue('C5', 'SIN REGISTRO');
        $general->setCellValue('D5', 'ZZZZ140101HOCRRNA9');
        $general->setCellValue('E5', 'A');
        $general->setCellValue('F5', '5021');
        $general->setCellValue('G5', '14/09/2019');
        $general->setCellValue('H5', '13.05');
        $general->setCellValue('I5', 'MUJER');
        $base = $book->createSheet();
        $base->setTitle('BASE DATOS');
        (new Xlsx($book))->save($path);
    }
}
