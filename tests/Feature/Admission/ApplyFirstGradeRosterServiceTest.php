<?php

namespace Tests\Feature\Admission;

use App\Console\Commands\temporal\ApplyFirstGradeRosterService;
use App\Enums\DocumentsStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\PaymentStatus;
use App\Enums\PreEnrollmentStatus;
use App\Enums\PromotionResult;
use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\AdmissionIntakeSetting;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\PreEnrollment;
use App\Models\Profile;
use App\Models\Student;
use App\Models\Workshop;
use App\Models\WorkshopEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ApplyFirstGradeRosterServiceTest extends TestCase
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

    public function test_roster_updates_pre_enrollment_and_enrolls_group_and_workshop(): void
    {
        AdmissionIntakeSetting::current()->update([
            'allow_convert_without_complete_docs' => true,
            'allow_convert_without_complete_data' => true,
            'allow_convert_without_payment' => true,
            'require_exam_before_convert' => false,
            'late_intake_enabled' => true,
        ]);

        $year = AcademicYear::factory()->create([
            'is_active' => true,
            'year_start' => '2026',
            'year_end' => '2027',
        ]);
        $grade = GradeLevel::query()->create(['name' => '1°']);
        $groupA = ClassGroup::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'name' => 'A',
        ]);
        $groupB = ClassGroup::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'name' => 'B',
        ]);
        $diseno = Workshop::query()->create([
            'name' => 'Diseño Industrial',
            'code' => 'DISENO',
            'is_active' => true,
            'is_internal' => false,
        ]);
        $ofimatica = Workshop::query()->updateOrCreate(
            ['code' => Workshop::OFIMATICA_CODE],
            [
                'name' => 'Ofimática',
                'is_active' => true,
                'is_internal' => true,
            ]
        );
        $informatica = Workshop::query()->create([
            'name' => 'Informática',
            'code' => 'INFORMATICA',
            'is_active' => true,
            'is_internal' => false,
        ]);

        $pre = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::PENDING,
            'payment_status' => PaymentStatus::PENDING,
            'curp' => 'AEHV140919MOCRRNA0',
            'first_name' => 'VIEJO',
            'last_name' => 'NOMBRE',
            'second_last_name' => 'PREVIO',
            'birth_date' => '2014-09-19',
            'gender' => 'M',
            'street_name' => 'CALLE VIEJA',
            'house_number' => '1',
            'review_notes' => 'Revisión inicial',
        ]);
        $folio = $pre->folio;

        $near = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::PENDING,
            'payment_status' => PaymentStatus::PENDING,
            'curp' => 'MACA140603HOCRXXA2',
            'first_name' => 'AXEL GABRIEL',
            'last_name' => 'MARTINEZ',
            'second_last_name' => 'CANAS',
            'birth_date' => '2014-06-03',
        ]);

        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'roster-1-test.xlsx';
        $this->writeRoster($this->path);

        $service = app(ApplyFirstGradeRosterService::class);
        $summary = $service->apply($this->path, $year->id, dryRun: false);

        $this->assertSame(3, $summary['applied']);
        $this->assertSame(1, $summary['near_matches']);
        $this->assertSame([], $summary['skipped']);
        $this->assertSame([], $summary['errors']);
        $this->assertSame(1, $summary['not_in_pre_count']);
        $this->assertSame('SIN REGISTRO PREVIO', $summary['manual_adds'][0]['name']);
        $this->assertSame('SIN REGISTRO PREVIO', $summary['created_late'][0]['name']);
        $this->assertSame('MACA140603HOCRXXXA2', $summary['curp_comparisons'][0]['excel_curp']);
        $this->assertSame('MACA140603HOCRXXA2', $summary['curp_comparisons'][0]['db_curp']);
        $this->assertSame('Se quedó la CURP de la base', $summary['curp_comparisons'][0]['decision']);
        $this->assertNotEmpty($summary['age_mismatches']);

        $pre->refresh();
        $this->assertSame('VANIA ZOE', $pre->first_name);
        $this->assertSame('ARELLANES', $pre->last_name);
        $this->assertSame('HERNANDEZ', $pre->second_last_name);
        $this->assertSame('2014-09-19', substr((string) $pre->birth_date, 0, 10));
        $this->assertSame('F', $pre->gender);
        $this->assertSame('EMILIANO ZAPATA', $pre->street_name);
        $this->assertSame('402', $pre->house_number);
        $this->assertNull($pre->unit_number);
        $this->assertSame($folio, $pre->folio);
        $this->assertSame(DocumentsStatus::PENDING, $pre->documents_status);
        $this->assertSame(PaymentStatus::PENDING, $pre->payment_status);
        $this->assertSame(PreEnrollmentStatus::APPROVED, $pre->status);
        $this->assertStringContainsString('Revisión inicial', (string) $pre->review_notes);
        $this->assertStringContainsString('Lista 1° 26-27: FOTOS', (string) $pre->review_notes);

        $enrollment = Enrollment::query()->find($pre->converted_enrollment_id);
        $this->assertNotNull($enrollment);
        $this->assertSame($groupB->id, $enrollment->class_group_id);
        $this->assertSame('placed', $enrollment->placement_status);
        $this->assertSame('late', $enrollment->admission_channel);

        $workshop = WorkshopEnrollment::query()->where('student_id', $pre->converted_student_id)->first();
        $this->assertSame($diseno->id, $workshop?->workshop_id);
        $this->assertSame(WorkshopEnrollmentSource::Manual, $workshop?->source);
        $this->assertSame(WorkshopEnrollmentStatus::Assigned, $workshop?->status);

        $near->refresh();
        $this->assertSame('MACA140603HOCRXXA2', $near->curp);
        $this->assertSame(PreEnrollmentStatus::APPROVED, $near->status);
        $nearWorkshop = WorkshopEnrollment::query()->where('student_id', $near->converted_student_id)->first();
        $this->assertSame($ofimatica->id, $nearWorkshop?->workshop_id);
        $nearEnrollment = Enrollment::query()->find($near->converted_enrollment_id);
        $this->assertSame($groupA->id, $nearEnrollment?->class_group_id);

        $late = Student::query()->whereHas('profile', fn ($query) => $query->where('national_id', 'ZZZZ140101HOCRRNA9'))->first();
        $this->assertNotNull($late);
        $lateEnrollment = Enrollment::query()->where('student_id', $late->id)->first();
        $this->assertSame($groupA->id, $lateEnrollment?->class_group_id);
        $this->assertSame('late', $lateEnrollment?->admission_channel);
        $this->assertTrue($lateEnrollment?->is_new_admission);
        $this->assertSame($informatica->id, WorkshopEnrollment::query()->where('student_id', $late->id)->value('workshop_id'));

        $again = $service->apply($this->path, $year->id, dryRun: false);
        $this->assertSame(3, $again['applied']);
        $this->assertSame([], $again['errors']);
        $this->assertSame(3, Student::query()->count());
        $this->assertSame(3, Enrollment::query()->count());
        $this->assertSame(3, WorkshopEnrollment::query()->count());
        $this->assertCount(3, $again['already_in_first']);
    }

    public function test_new_directory_layout_creates_late_and_reports_first_grade_gaps(): void
    {
        $this->enableLateIntake();

        $origin = AcademicYear::factory()->create([
            'is_active' => true,
            'year_start' => '2025',
            'year_end' => '2026',
        ]);
        $year = AcademicYear::factory()->create([
            'is_active' => false,
            'year_start' => '2026',
            'year_end' => '2027',
        ]);
        $grade = GradeLevel::query()->create(['name' => '1°']);
        $originGroup = ClassGroup::query()->create([
            'academic_year_id' => $origin->id,
            'grade_level_id' => $grade->id,
            'name' => 'E',
        ]);
        $destA = ClassGroup::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'name' => 'A',
        ]);
        $destB = ClassGroup::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'name' => 'B',
        ]);
        $diseno = Workshop::query()->create([
            'name' => 'Diseño Industrial',
            'code' => 'DISENO',
            'is_active' => true,
        ]);
        Workshop::query()->create([
            'name' => 'Informática',
            'code' => 'INFORMATICA',
            'is_active' => true,
        ]);

        $pre = PreEnrollment::factory()->create([
            'status' => PreEnrollmentStatus::IN_REVIEW,
            'documents_status' => DocumentsStatus::PENDING,
            'payment_status' => PaymentStatus::PENDING,
            'curp' => 'AEHV140919MOCRRNA0',
            'first_name' => 'VANIA ZOE',
            'last_name' => 'ARELLANES',
            'second_last_name' => 'HERNANDEZ',
            'birth_date' => '2014-09-19',
            'gender' => 'F',
        ]);

        $existing = $this->makeStudent('CUMD120717HOCRNGA7', 'Diego Silvano', 'Cruz Mena');
        Enrollment::query()->create([
            'student_id' => $existing->id,
            'class_group_id' => $originGroup->id,
            'academic_year_id' => $origin->id,
            'status' => EnrollmentStatus::Active,
            'is_new_admission' => false,
        ]);

        $already = $this->makeStudent('BOMA141106HOCHCLA3', 'Alan Leonardo', 'Bohorquez Moctezuma');
        Enrollment::query()->create([
            'student_id' => $already->id,
            'class_group_id' => $destA->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
            'is_new_admission' => true,
            'admission_channel' => 'late',
            'placement_status' => 'placed',
        ]);

        $ghost = $this->makeStudent('ZZZZ130101HOCRRNA1', 'Fuera', 'De Lista');
        Enrollment::query()->create([
            'student_id' => $ghost->id,
            'class_group_id' => $destB->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
            'is_new_admission' => true,
            'admission_channel' => 'late',
        ]);

        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'roster-1-new-layout.xlsx';
        $this->writeNewDirectory($this->path);

        $summary = app(ApplyFirstGradeRosterService::class)->apply($this->path, $year->id, dryRun: false);

        $this->assertSame([], $summary['errors']);
        $this->assertSame(3, $summary['not_in_pre_count']);
        $this->assertCount(1, $summary['converted']);
        $this->assertCount(1, $summary['created_late']);
        $this->assertCount(1, $summary['placed_existing']);
        $this->assertCount(1, $summary['already_in_first']);
        $this->assertSame('Fuera De Lista', $summary['dest_first_not_on_list'][0]['name']);

        $pre->refresh();
        $this->assertSame(PreEnrollmentStatus::APPROVED, $pre->status);
        $this->assertSame($destA->id, Enrollment::query()->find($pre->converted_enrollment_id)?->class_group_id);
        $this->assertSame($diseno->id, WorkshopEnrollment::query()->where('student_id', $pre->converted_student_id)->value('workshop_id'));

        $existingOrigin = Enrollment::query()
            ->where('student_id', $existing->id)
            ->where('academic_year_id', $origin->id)
            ->first();
        $this->assertSame(EnrollmentStatus::Completed, $existingOrigin?->status);
        $this->assertSame(PromotionResult::RETAINED, $existingOrigin?->promotion_result);

        $existingDest = Enrollment::query()
            ->where('student_id', $existing->id)
            ->where('academic_year_id', $year->id)
            ->first();
        $this->assertSame($destB->id, $existingDest?->class_group_id);
        $this->assertSame('late', $existingDest?->admission_channel);
        $this->assertFalse((bool) $existingDest?->is_new_admission);

        $late = Student::query()->whereHas('profile', fn ($query) => $query->where('national_id', 'MAAA141229HOCRMXA0'))->first();
        $this->assertNotNull($late);
        $this->assertSame('late', Enrollment::query()->where('student_id', $late->id)->value('admission_channel'));
    }

    private function enableLateIntake(): void
    {
        AdmissionIntakeSetting::current()->update([
            'allow_convert_without_complete_docs' => true,
            'allow_convert_without_complete_data' => true,
            'allow_convert_without_payment' => true,
            'require_exam_before_convert' => false,
            'late_intake_enabled' => true,
        ]);
    }

    private function makeStudent(string $curp, string $first, string $last): Student
    {
        $profile = Profile::query()->create([
            'national_id' => $curp,
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'M',
            'birth_date' => '2012-07-17',
        ]);

        return Student::query()->create(['profile_id' => $profile->id]);
    }

    private function writeRoster(string $path): void
    {
        $sheet = new Spreadsheet();
        $sheet->getActiveSheet()->setTitle(ApplyFirstGradeRosterService::SHEET);
        $rows = [
            5 => ['VANIA ZOE', 'ARELLANES', 'HERNANDEZ', 'AEHV140919MOCRRNA0', 'B', 'MUJER', 'DISEÑO INDUSTRIAL', '402', 'FOTOS'],
            6 => ['AXEL GABRIEL', 'MARTINEZ', 'CANAS', 'MACA140603HOCRXXXA2', 'A', 'HOMBRE', 'OFIMATICA', '10', ''],
            7 => ['SIN', 'REGISTRO', 'PREVIO', 'ZZZZ140101HOCRRNA9', 'A', 'MUJER', 'INFORMATICA', '1', ''],
        ];
        $active = $sheet->getActiveSheet();
        foreach ($rows as $row => [$first, $paterno, $materno, $curp, $group, $gender, $tech, $number, $notes]) {
            $active->setCellValue('B'.$row, $first);
            $active->setCellValue('C'.$row, $paterno);
            $active->setCellValue('D'.$row, $materno);
            $active->setCellValue('F'.$row, $curp);
            $active->setCellValue('G'.$row, $group);
            $active->setCellValue('H'.$row, '14/09/2019');
            $active->setCellValue('L'.$row, $gender);
            $active->setCellValue('M'.$row, $tech);
            $active->setCellValue('N'.$row, 'CALLE');
            $active->setCellValue('O'.$row, 'EMILIANO ZAPATA');
            $active->setCellValue('P'.$row, $number);
            $active->setCellValue('R'.$row, 'COLONIA');
            $active->setCellValue('S'.$row, 'DOLORES');
            $active->setCellValue('T'.$row, 'HERNANDEZ');
            $active->setCellValue('U'.$row, 'PEREZ');
            $active->setCellValue('V'.$row, 'FLOR');
            $active->setCellValue('W'.$row, 'HEPF781003MOCRRL06');
            $active->setCellValue('X'.$row, '9512441693');
            $active->setCellValue('Y'.$row, 'MADRE');
            $active->setCellValue('Z'.$row, $notes);
        }

        (new Xlsx($sheet))->save($path);
    }

    private function writeNewDirectory(string $path): void
    {
        $sheet = new Spreadsheet();
        $active = $sheet->getActiveSheet();
        $active->setTitle(ApplyFirstGradeRosterService::SHEET);
        $active->setCellValue('G4', 'CURP DEL ASPIRANTE');
        $rows = [
            5 => ['VANIA ZOE', 'ARELLANES', 'HERNANDEZ', 'AEHV140919MOCRRNA0', 'A', 'MUJER', 'DISEÑO INDUSTRIAL'],
            6 => ['Alan Leonardo', 'Bohorquez', 'Moctezuma', 'BOMA141106HOCHCLA3', 'A', 'HOMBRE', 'DISEÑO INDUSTRIAL'],
            7 => ['Diego Silvano', 'Cruz', 'Mena', 'CUMD120717HOCRNGA7', 'B', 'HOMBRE', 'INFORMATICA'],
            8 => ['HURI', 'MARTINEZ', 'AGUILAR', 'MAAA141229HOCRMXA0', 'A', 'MUJER', 'DISEÑO INDUSTRIAL'],
        ];
        foreach ($rows as $row => [$first, $paterno, $materno, $curp, $group, $gender, $tech]) {
            $active->setCellValue('C'.$row, $first);
            $active->setCellValue('D'.$row, $paterno);
            $active->setCellValue('E'.$row, $materno);
            $active->setCellValue('G'.$row, $curp);
            $active->setCellValue('H'.$row, $group);
            $active->setCellValue('I'.$row, '19/09/2014');
            $active->setCellValue('M'.$row, $gender);
            $active->setCellValue('N'.$row, $tech);
            $active->setCellValue('O'.$row, 'CALLE');
            $active->setCellValue('P'.$row, 'EMILIANO ZAPATA');
            $active->setCellValue('Q'.$row, '402');
            $active->setCellValue('S'.$row, 'COLONIA');
            $active->setCellValue('T'.$row, 'DOLORES');
            $active->setCellValue('U'.$row, 'HERNANDEZ');
            $active->setCellValue('V'.$row, 'PEREZ');
            $active->setCellValue('W'.$row, 'FLOR');
            $active->setCellValue('X'.$row, 'HEPF781003MOCRRL06');
            $active->setCellValue('Y'.$row, '9512441693');
            $active->setCellValue('Z'.$row, 'MADRE');
        }

        (new Xlsx($sheet))->save($path);
    }
}
