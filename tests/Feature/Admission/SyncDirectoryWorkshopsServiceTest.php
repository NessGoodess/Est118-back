<?php

namespace Tests\Feature\Admission;

use App\Console\Commands\temporal\SyncDirectoryWorkshopsService;
use App\Enums\EnrollmentStatus;
use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Profile;
use App\Models\Student;
use App\Models\Workshop;
use App\Models\WorkshopEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SyncDirectoryWorkshopsServiceTest extends TestCase
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

    public function test_changes_and_adds_workshops_from_the_directory(): void
    {
        $year = AcademicYear::factory()->create(['is_active' => false, 'year_start' => '2026', 'year_end' => '2027']);
        $grade = GradeLevel::query()->create(['name' => '3°']);
        $group = ClassGroup::query()->create(['academic_year_id' => $year->id, 'grade_level_id' => $grade->id, 'name' => 'A']);
        $diseno = Workshop::query()->firstOrCreate(['code' => 'DISENO'], ['name' => 'Diseño Industrial', 'is_active' => true]);
        $maquinas = Workshop::query()->firstOrCreate(['code' => 'MAQUINAS'], ['name' => 'Máquinas, herramientas y sistemas de control', 'is_active' => true]);

        $moved = $this->student($year, $group, 'MECD121012HDFSNGA7', 'DIEGO MATEO', 'MESTAS CANCIO');
        $this->assign($moved, $year, $maquinas);
        $missing = $this->student($year, $group, 'AERS120301MOCVSFA3', 'SOFIA', 'AVENDAÑO RIOS');
        $same = $this->student($year, $group, 'BEPA120101MOCRRMA7', 'AMBER', 'BERNARDO PERALTA');
        $this->assign($same, $year, $diseno);

        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sync-workshops-'.uniqid().'.xlsx';
        $this->writeThird([
            ['MESTAS CANCIO DIEGO MATEO', 'MECD121012HDFSNGA7', '3011'],
            ['AVENDAÑO RIOS SOFIA', 'AERS120301MOCVSFA3', '3011'],
            ['BERNARDO PERALTA AMBER', 'BEPA120101MOCRRMA7', '3011'],
            ['NO INSCRITO NADIE', 'NOIN120101HOCRRNA1', '3011'],
        ]);

        $dry = app(SyncDirectoryWorkshopsService::class)->sync($this->path, $year->id, '3°', dryRun: true);
        $this->assertCount(1, $dry['changed']);
        $this->assertCount(1, $dry['added']);
        $this->assertSame(1, $dry['unchanged']);
        $this->assertSame($maquinas->id, (int) WorkshopEnrollment::query()->where('student_id', $moved->id)->value('workshop_id'));

        $summary = app(SyncDirectoryWorkshopsService::class)->sync($this->path, $year->id, '3°', dryRun: false);
        $this->assertSame([], $summary['errors']);
        $this->assertSame(2, $summary['applied']);
        $this->assertSame('Máquinas, herramientas y sistemas de control', $summary['changed'][0]['from']);
        $this->assertSame('Diseño Industrial', $summary['changed'][0]['to']);
        $this->assertSame('sin taller', $summary['added'][0]['from']);
        $this->assertCount(1, $summary['skipped']);
        $this->assertSame($diseno->id, (int) WorkshopEnrollment::query()->where('student_id', $moved->id)->value('workshop_id'));
        $this->assertSame($diseno->id, (int) WorkshopEnrollment::query()->where('student_id', $missing->id)->value('workshop_id'));

        $again = app(SyncDirectoryWorkshopsService::class)->sync($this->path, $year->id, '3°', dryRun: false);
        $this->assertSame(3, $again['unchanged']);
        $this->assertSame(0, $again['applied']);
    }

    private function student(AcademicYear $year, ClassGroup $group, string $curp, string $first, string $last): Student
    {
        $profile = Profile::query()->create([
            'national_id' => $curp,
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'M',
            'birth_date' => '2012-01-01',
        ]);
        $student = Student::query()->create(['profile_id' => $profile->id]);
        Enrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'class_group_id' => $group->id,
            'status' => EnrollmentStatus::Active,
            'is_new_admission' => false,
        ]);

        return $student;
    }

    private function assign(Student $student, AcademicYear $year, Workshop $workshop): void
    {
        WorkshopEnrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'workshop_id' => $workshop->id,
            'source' => WorkshopEnrollmentSource::Manual,
            'status' => WorkshopEnrollmentStatus::Assigned,
        ]);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string}>  $rows
     */
    private function writeThird(array $rows): void
    {
        $sheet = new Spreadsheet();
        $active = $sheet->getActiveSheet();
        $active->setTitle('DATOS GENERALES');
        foreach (['B4' => 'DATOS DEL ALUMNO', 'F4' => 'DIRECCIÓN', 'M4' => 'DATOS DEL PADRE, MADRE O TUTOR'] as $cell => $text) {
            $active->setCellValue($cell, $text);
        }
        foreach ([
            'B5' => 'NOMBRE COMPLETO', 'C5' => 'CURP DEL ASPIRANTE', 'D5' => 'GRUPO', 'E5' => 'TECNOLOGIA',
            'M5' => 'APELLIDO PATERNO', 'N5' => 'APELLIDO MATERNO', 'O5' => 'NOMBRE', 'P5' => 'NUMERO DE CONTACTO',
        ] as $cell => $text) {
            $active->setCellValue($cell, $text);
        }
        foreach ($rows as $index => [$full, $curp, $tech]) {
            $r = $index + 6;
            $active->setCellValue('B'.$r, $full);
            $active->setCellValue('C'.$r, $curp);
            $active->setCellValue('D'.$r, 'A');
            $active->setCellValueExplicit('E'.$r, $tech, DataType::TYPE_STRING);
        }

        (new Xlsx($sheet))->save($this->path);
    }
}
