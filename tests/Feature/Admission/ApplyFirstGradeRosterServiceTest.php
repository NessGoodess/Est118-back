<?php

namespace Tests\Feature\Admission;

use App\Enums\DocumentsStatus;
use App\Enums\PaymentStatus;
use App\Enums\PreEnrollmentStatus;
use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\AdmissionIntakeSetting;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\PreEnrollment;
use App\Models\Student;
use App\Models\Workshop;
use App\Models\WorkshopEnrollment;
use App\Console\Commands\temporal\ApplyFirstGradeRosterService;
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

        $this->assertSame(2, $summary['applied']);
        $this->assertSame(1, $summary['near_matches']);
        $this->assertCount(1, $summary['skipped']);
        $this->assertSame([], $summary['errors']);
        $this->assertSame('SIN REGISTRO PREVIO', $summary['manual_adds'][0]['name']);
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

        $again = $service->apply($this->path, $year->id, dryRun: false);
        $this->assertSame(2, $again['applied']);
        $this->assertSame([], $again['errors']);
        $this->assertSame(2, Student::query()->count());
        $this->assertSame(2, Enrollment::query()->count());
        $this->assertSame(2, WorkshopEnrollment::query()->count());
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
}
