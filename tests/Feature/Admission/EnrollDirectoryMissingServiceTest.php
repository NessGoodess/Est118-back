<?php

namespace Tests\Feature\Admission;

use App\Console\Commands\temporal\EnrollDirectoryMissingService;
use App\Enums\EnrollmentStatus;
use App\Enums\PromotionResult;
use App\Enums\ReEnrollmentPeriodStatus;
use App\Enums\ReEnrollmentProcessStep;
use App\Enums\ReEnrollmentValidationStatus;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Profile;
use App\Models\School\ReEnrollmentApplication;
use App\Models\School\ReEnrollmentPeriod;
use App\Models\Student;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class EnrollDirectoryMissingServiceTest extends TestCase
{
    use RefreshDatabase;

    private string $path = '';

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_file($this->path)) {
            unlink($this->path);
        }

        parent::tearDown();
    }

    public function test_creates_late_intake_promotes_previous_grade_and_retains_same_grade(): void
    {
        $from = AcademicYear::factory()->create(['is_active' => true, 'year_start' => '2025', 'year_end' => '2026']);
        $to = AcademicYear::factory()->create(['is_active' => false, 'year_start' => '2026', 'year_end' => '2027']);
        $second = GradeLevel::query()->create(['name' => '2°']);
        $third = GradeLevel::query()->create(['name' => '3°']);
        $secondB = ClassGroup::query()->create(['academic_year_id' => $from->id, 'grade_level_id' => $second->id, 'name' => 'B']);
        $thirdH = ClassGroup::query()->create(['academic_year_id' => $from->id, 'grade_level_id' => $third->id, 'name' => 'H']);
        $thirdA = ClassGroup::query()->create(['academic_year_id' => $to->id, 'grade_level_id' => $third->id, 'name' => 'A']);
        $thirdD = ClassGroup::query()->create(['academic_year_id' => $to->id, 'grade_level_id' => $third->id, 'name' => 'D']);
        $diseno = Workshop::query()->firstOrCreate(['code' => 'DISENO'], ['name' => 'Diseño Industrial', 'is_active' => true]);
        Workshop::query()->firstOrCreate(['code' => 'OFIMATICA'], ['name' => 'Ofimática', 'is_active' => true]);
        $period = ReEnrollmentPeriod::query()->create([
            'name' => '2026-2027',
            'from_academic_year_id' => $from->id,
            'to_academic_year_id' => $to->id,
            'status' => ReEnrollmentPeriodStatus::OPEN,
            'current_step' => ReEnrollmentProcessStep::COMPLETED,
            'keep_current_groups' => true,
            'created_by' => User::factory()->create()->id,
        ]);

        $pending = $this->student('PEND120101HOCRRNA1', 'RAUL', 'PEREZ LOPEZ');
        $pendingOrigin = $this->enroll($pending, $from, $secondB, ['status' => EnrollmentStatus::Active]);
        $this->application($period, $pendingOrigin);

        $graduated = $this->student('LUVD111220MOCSSNA4', 'DANIELA', 'LUIS VASQUEZ');
        $graduatedOrigin = $this->enroll($graduated, $from, $thirdH, [
            'status' => EnrollmentStatus::Completed,
            'is_approved' => true,
            'promotion_result' => PromotionResult::GRADUATED,
        ]);
        $this->application($period, $graduatedOrigin);

        $already = $this->student('YAIN120101MOCRRNA2', 'ANA', 'YA INSCRITA');
        $this->enroll($already, $to, $thirdA, ['status' => EnrollmentStatus::Active]);

        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'enroll-missing-'.uniqid().'.xlsx';
        $this->writeThird([
            ['INSCRITA YA ANA', 'YAIN120101MOCRRNA2', 'A', '3011', ''],
            ['PEREZ LOPEZ RAUL', 'PEND120101HOCRRNA1', 'A', '6031', ''],
            ['LUIS VASQUEZ DANIELA', 'LUVD111220MOCSSNA4', 'D', '3011', ''],
            ['CORTAZAR EDELYN', 'COXE120213MNERXDA0', 'A', '3011', '951 111 2233'],
            ['SIN CURP VALIDA', 'MAAA141229HOCRMX0', 'A', '3011', ''],
        ]);

        $dry = app(EnrollDirectoryMissingService::class)->enroll($this->path, $to->id, '3°', dryRun: true);
        $this->assertSame(1, $dry['already']);
        $this->assertCount(1, $dry['created']);
        $this->assertCount(1, $dry['promoted']);
        $this->assertCount(1, $dry['retained']);
        $this->assertCount(1, $dry['skipped']);
        $this->assertNull(Profile::query()->where('national_id', 'COXE120213MNERXDA0')->first());

        $summary = app(EnrollDirectoryMissingService::class)->enroll($this->path, $to->id, '3°', dryRun: false);
        $this->assertSame([], $summary['errors']);
        $this->assertSame(3, $summary['applied']);

        $pendingOrigin->refresh();
        $this->assertSame(EnrollmentStatus::Completed, $pendingOrigin->status);
        $this->assertTrue((bool) $pendingOrigin->is_approved);
        $this->assertSame(PromotionResult::PROMOTED, $pendingOrigin->promotion_result);
        $promotedDest = Enrollment::query()->where('student_id', $pending->id)->where('academic_year_id', $to->id)->first();
        $this->assertSame($thirdA->id, (int) $promotedDest->class_group_id);
        $this->assertFalse((bool) $promotedDest->is_new_admission);
        $this->assertTrue((bool) ReEnrollmentApplication::query()->where('student_id', $pending->id)->value('passed_cycle'));

        $graduatedOrigin->refresh();
        $this->assertFalse((bool) $graduatedOrigin->is_approved);
        $this->assertSame(PromotionResult::RETAINED, $graduatedOrigin->promotion_result);
        $retainedDest = Enrollment::query()->where('student_id', $graduated->id)->where('academic_year_id', $to->id)->first();
        $this->assertSame($thirdD->id, (int) $retainedDest->class_group_id);
        $this->assertSame(PromotionResult::RETAINED, $retainedDest->promotion_result);
        $this->assertSame(ReEnrollmentValidationStatus::VALIDATED, ReEnrollmentApplication::query()->where('student_id', $graduated->id)->first()->status);

        $profile = Profile::query()->where('national_id', 'COXE120213MNERXDA0')->first();
        $this->assertSame('EDELYN', $profile->first_name);
        $this->assertSame('CORTAZAR', $profile->last_name);
        $this->assertSame('2012-02-13', substr((string) $profile->birth_date, 0, 10));
        $this->assertSame('F', $profile->gender);
        $this->assertSame('OAXACA DE JUAREZ', $profile->address->city);
        $late = Enrollment::query()->where('student_id', $profile->student->id)->first();
        $this->assertTrue((bool) $late->is_new_admission);
        $this->assertSame('late', $late->admission_channel);
        $this->assertSame($thirdA->id, (int) $late->class_group_id);
        $this->assertSame($diseno->id, (int) WorkshopEnrollment::query()->where('student_id', $profile->student->id)->value('workshop_id'));
        $tutor = $profile->student->guardians()->first();
        $this->assertSame('9511112233', $tutor?->profile?->phone_number);
        $this->assertSame('NO_ESPECIFICADO_1', $tutor?->profile?->national_id);
        $this->assertNull($tutor?->Kinship);
        $this->assertNull($tutor?->pivot?->relationship);
        $this->assertSame('NO_ESPECIFICADO', $profile->address->state);
        $this->assertNull($profile->student->previous_school);
        $this->assertNull($profile->student->current_average);

        $again = app(EnrollDirectoryMissingService::class)->enroll($this->path, $to->id, '3°', dryRun: false);
        $this->assertSame(4, $again['already']);
        $this->assertSame(0, $again['applied']);
    }

    private function student(string $curp, string $first, string $last): Student
    {
        $profile = Profile::query()->create([
            'national_id' => $curp,
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'M',
            'birth_date' => '2012-01-01',
        ]);

        return Student::query()->create(['profile_id' => $profile->id]);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function enroll(Student $student, AcademicYear $year, ClassGroup $group, array $extra): Enrollment
    {
        return Enrollment::query()->create(array_merge([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'class_group_id' => $group->id,
            'is_new_admission' => false,
        ], $extra));
    }

    private function application(ReEnrollmentPeriod $period, Enrollment $enrollment): void
    {
        ReEnrollmentApplication::query()->create([
            're_enrollment_period_id' => $period->id,
            'enrollment_id' => $enrollment->id,
            'student_id' => $enrollment->student_id,
            'status' => ReEnrollmentValidationStatus::VALIDATED,
            'passed_cycle' => null,
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string}>  $rows
     */
    private function writeThird(array $rows): void
    {
        $sheet = new Spreadsheet();
        $active = $sheet->getActiveSheet();
        $active->setTitle('DATOS GENERALES');
        foreach (['B4' => 'DATOS DEL ALUMNO', 'F4' => 'DIRECCIÓN', 'M4' => 'DATOS DEL PADRE, MADRE O TUTOR'] as $cell => $text) {
            $active->setCellValue($cell, $text);
        }
        $headers = [
            'B5' => 'NOMBRE COMPLETO', 'C5' => 'CURP DEL ASPIRANTE', 'D5' => 'GRUPO', 'E5' => 'TECNOLOGIA',
            'F5' => 'VIALIDAD', 'G5' => 'NOMBRE DE LA VIALIDAD', 'H5' => 'NÚMERO INTERIOR', 'I5' => 'NÚMERO EXTERIOR',
            'J5' => 'ASENTAMIENTO', 'K5' => 'NOMBRE DEL ASENTAMIENTO', 'L5' => 'MUNICIPIO',
            'M5' => 'APELLIDO PATERNO', 'N5' => 'APELLIDO MATERNO', 'O5' => 'NOMBRE', 'P5' => 'NUMERO DE CONTACTO',
        ];
        foreach ($headers as $cell => $text) {
            $active->setCellValue($cell, $text);
        }
        foreach ($rows as $index => [$full, $curp, $group, $tech, $phone]) {
            $r = $index + 6;
            $active->setCellValue('B'.$r, $full);
            $active->setCellValue('C'.$r, $curp);
            $active->setCellValue('D'.$r, $group);
            $active->setCellValueExplicit('E'.$r, $tech, DataType::TYPE_STRING);
            $active->setCellValue('F'.$r, 'CALLE');
            $active->setCellValue('G'.$r, 'RIO ATOYAC');
            $active->setCellValue('I'.$r, '600');
            $active->setCellValue('J'.$r, 'FRACCIONAMIENTO');
            $active->setCellValue('K'.$r, 'LOS RIOS');
            $active->setCellValue('L'.$r, 'OAXACA DE JUAREZ');
            $active->setCellValue('M'.$r, 'RIOS');
            $active->setCellValue('N'.$r, 'GONZALEZ');
            $active->setCellValue('O'.$r, 'CONSTANZA');
            $active->setCellValueExplicit('P'.$r, $phone, DataType::TYPE_STRING);
        }

        (new Xlsx($sheet))->save($this->path);
    }
}
