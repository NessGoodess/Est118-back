<?php

namespace Tests\Feature\Admission;

use App\Console\Commands\temporal\SyncSecondGradePrimaryTutorsService;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Guardian;
use App\Models\Profile;
use App\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class SyncSecondGradePrimaryTutorsServiceTest extends TestCase
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

    public function test_preview_lists_matches_and_non_matches_without_writing(): void
    {
        [$year, $group] = $this->period();
        $matched = $this->makeStudent('BECA131211HOCTRRA7', 'ARTURO ALEJANDRO', 'BETANZOS CRUZ');
        $this->enroll($matched, $year, $group);
        $tutor = $this->linkPrimary($matched, 'OLDG940321MOCRNN07', 'VANESSA', 'CRUZ GONZALEZ', '1111111111', 'TIA');

        $onlyDb = $this->makeStudent('ONLY131211HOCTRRA1', 'SOLO', 'EN BASE');
        $this->enroll($onlyDb, $year, $group);
        $this->linkPrimary($onlyDb, 'TUTONLY131211HOC01', 'PADRE', 'SOLO', '2222222222', 'PADRE');

        $this->path = $this->tempPath('second-tutors-preview');
        $this->writeDirectory($this->path, [
            [
                'first' => 'ARTURO ALEJANDRO',
                'paterno' => 'BETANZOS',
                'materno' => 'CRUZ',
                'curp' => 'BECA131211HOCTRRA7',
                'group' => 'A',
                'g_paterno' => 'CRUZ',
                'g_materno' => 'GONZALEZ',
                'g_name' => 'VANESSA',
                'g_curp' => 'CUGV940321MOCRNN07',
                'phone' => '9513121405',
                'kinship' => 'MADRE',
            ],
            [
                'first' => 'FANTASMA',
                'paterno' => 'NO',
                'materno' => 'EXISTE',
                'curp' => 'FANT000101HDFRNS09',
                'group' => 'A',
                'g_paterno' => 'X',
                'g_materno' => 'Y',
                'g_name' => 'Z',
                'g_curp' => '',
                'phone' => '9510000000',
                'kinship' => 'MADRE',
            ],
        ]);

        $summary = app(SyncSecondGradePrimaryTutorsService::class)->preview($this->path, $year->id);

        $this->assertTrue($summary['dry_run']);
        $this->assertCount(1, $summary['matched']);
        $this->assertTrue($summary['matched'][0]['will_update']);
        $this->assertStringContainsString('teléfono', implode(' ', $summary['matched'][0]['changes']));
        $this->assertStringContainsString('CURP', implode(' ', $summary['matched'][0]['changes']));
        $this->assertStringContainsString('parentesco', implode(' ', $summary['matched'][0]['changes']));
        $this->assertCount(1, $summary['unmatched_excel']);
        $this->assertSame('FANTASMA NO EXISTE', $summary['unmatched_excel'][0]['student']);
        $this->assertCount(1, $summary['unmatched_db']);
        $this->assertSame($onlyDb->id, $summary['unmatched_db'][0]['student_id']);

        $tutor->refresh()->load('profile');
        $this->assertSame('1111111111', $tutor->profile->phone_number);
        $this->assertSame('OLDG940321MOCRNN07', $tutor->profile->national_id);
    }

    public function test_apply_updates_primary_tutor_and_skips_unmatched(): void
    {
        [$year, $group] = $this->period();
        $matched = $this->makeStudent('BECA131211HOCTRRA7', 'ARTURO ALEJANDRO', 'BETANZOS CRUZ');
        $this->enroll($matched, $year, $group);
        $tutor = $this->linkPrimary($matched, 'OLDG940321MOCRNN07', 'VANESSA', 'CRUZ GONZALEZ', '1111111111', 'TIA');

        $this->path = $this->tempPath('second-tutors-apply');
        $this->writeDirectory($this->path, [
            [
                'first' => 'ARTURO ALEJANDRO',
                'paterno' => 'BETANZOS',
                'materno' => 'CRUZ',
                'curp' => 'BECA131211HOCTRRA7',
                'group' => 'A',
                'g_paterno' => 'CRUZ',
                'g_materno' => 'GONZALEZ',
                'g_name' => 'VANESSA',
                'g_curp' => 'CUGV940321MOCRNN07',
                'phone' => '9513121405',
                'kinship' => 'MADRE',
            ],
            [
                'first' => 'FANTASMA',
                'paterno' => 'NO',
                'materno' => 'EXISTE',
                'curp' => '',
                'group' => 'B',
                'g_paterno' => '',
                'g_materno' => '',
                'g_name' => '',
                'g_curp' => '',
                'phone' => '9519999999',
                'kinship' => 'PADRE',
            ],
        ]);

        $summary = app(SyncSecondGradePrimaryTutorsService::class)->apply($this->path, $year->id);

        $this->assertSame(1, $summary['applied']);
        $this->assertSame([], $summary['errors']);

        $tutor->refresh()->load('profile');
        $matched->refresh()->load('guardians');
        $this->assertSame('9513121405', $tutor->profile->phone_number);
        $this->assertSame('CUGV940321MOCRNN07', $tutor->profile->national_id);
        $this->assertSame('MADRE', $matched->guardians->first()->pivot->relationship);
    }

    public function test_does_not_overwrite_tutor_curp_when_taken_by_another_profile(): void
    {
        [$year, $group] = $this->period();
        $matched = $this->makeStudent('BECA131211HOCTRRA7', 'ARTURO ALEJANDRO', 'BETANZOS CRUZ');
        $this->enroll($matched, $year, $group);
        $tutor = $this->linkPrimary($matched, 'OLDG940321MOCRNN07', 'VANESSA', 'CRUZ GONZALEZ', '1111111111', 'MADRE');
        Profile::query()->create([
            'national_id' => 'CUGV940321MOCRNN07',
            'first_name' => 'OTRA',
            'last_name' => 'PERSONA',
            'gender' => 'F',
        ]);

        $this->path = $this->tempPath('second-tutors-curp-taken');
        $this->writeDirectory($this->path, [
            [
                'first' => 'ARTURO ALEJANDRO',
                'paterno' => 'BETANZOS',
                'materno' => 'CRUZ',
                'curp' => 'BECA131211HOCTRRA7',
                'group' => 'A',
                'g_paterno' => 'CRUZ',
                'g_materno' => 'GONZALEZ',
                'g_name' => 'VANESSA',
                'g_curp' => 'CUGV940321MOCRNN07',
                'phone' => '9513121405',
                'kinship' => 'MADRE',
            ],
        ]);

        $summary = app(SyncSecondGradePrimaryTutorsService::class)->apply($this->path, $year->id);

        $this->assertSame(1, $summary['applied']);
        $tutor->refresh()->load('profile');
        $this->assertSame('9513121405', $tutor->profile->phone_number);
        $this->assertSame('OLDG940321MOCRNN07', $tutor->profile->national_id);
        $this->assertNotEmpty($summary['matched'][0]['problems']);
    }

    /**
     * @return array{0: AcademicYear, 1: ClassGroup}
     */
    private function period(): array
    {
        $year = AcademicYear::factory()->create([
            'is_active' => true,
            'year_start' => '2026',
            'year_end' => '2027',
        ]);
        $grade = GradeLevel::query()->firstOrCreate(['name' => '2°'], ['sort_order' => 2]);
        $group = ClassGroup::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'name' => 'A',
        ]);

        return [$year, $group];
    }

    private function makeStudent(string $curp, string $first, string $last): Student
    {
        $profile = Profile::query()->create([
            'national_id' => $curp,
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'M',
        ]);

        return Student::query()->create(['profile_id' => $profile->id]);
    }

    private function enroll(Student $student, AcademicYear $year, ClassGroup $group): void
    {
        Enrollment::query()->create([
            'student_id' => $student->id,
            'academic_year_id' => $year->id,
            'class_group_id' => $group->id,
            'status' => EnrollmentStatus::Active,
        ]);
    }

    private function linkPrimary(
        Student $student,
        string $curp,
        string $first,
        string $last,
        string $phone,
        string $kinship
    ): Guardian {
        $profile = Profile::query()->create([
            'national_id' => $curp,
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'F',
            'phone_number' => $phone,
        ]);
        $guardian = Guardian::query()->create(['profile_id' => $profile->id, 'Kinship' => $kinship]);
        $student->guardians()->syncWithoutDetaching([
            $guardian->id => [
                'relationship' => $kinship,
                'is_primary' => true,
                'sort_order' => 0,
            ],
        ]);

        return $guardian;
    }

    private function tempPath(string $name): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.$name.'-'.uniqid().'.xlsx';
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function writeDirectory(string $path, array $rows): void
    {
        $sheet = new Spreadsheet();
        $active = $sheet->getActiveSheet();
        $active->setTitle('DIRECTORIO');
        foreach ([
            'B3' => 'DATOS DEL ALUMNO',
            'T3' => 'TUTOR',
            'X3' => 'NÚMERO DE CONTACTO',
            'Y3' => 'PARENTESCO DEL ALUMNO',
            'B4' => 'NOMBRE',
            'C4' => 'APELLIDO PATERNO',
            'D4' => 'APELLIDO MATERNO',
            'F4' => 'CURP DEL ASPIRANTE',
            'G4' => 'GRUPO',
            'T4' => 'APELLIDO PATERNO',
            'U4' => 'APELLIDO MATERNO',
            'V4' => 'NOMBRE',
            'W4' => 'CURP TUTOR',
        ] as $cell => $text) {
            $active->setCellValue($cell, $text);
        }

        foreach ($rows as $index => $row) {
            $r = $index + 5;
            $active->setCellValueExplicit('B'.$r, $row['first'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('C'.$r, $row['paterno'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('D'.$r, $row['materno'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('F'.$r, $row['curp'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('G'.$r, $row['group'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('T'.$r, $row['g_paterno'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('U'.$r, $row['g_materno'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('V'.$r, $row['g_name'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('W'.$r, $row['g_curp'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('X'.$r, $row['phone'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('Y'.$r, $row['kinship'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }

        (new Xlsx($sheet))->save($path);
    }
}
