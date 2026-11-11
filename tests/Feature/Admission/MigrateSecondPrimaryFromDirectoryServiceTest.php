<?php

namespace Tests\Feature\Admission;

use App\Console\Commands\temporal\MigrateSecondPrimaryFromDirectoryService;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Address;
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

class MigrateSecondPrimaryFromDirectoryServiceTest extends TestCase
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

    public function test_preview_does_not_write_and_apply_overwrites_primary_and_shares_address(): void
    {
        [$year, $group] = $this->period('2°');
        $student = $this->makeStudent('BECA131211HOCTRRA7', 'ARTURO', 'BETANZOS');
        $this->enroll($student, $year, $group, '2°');
        $primary = $this->link($student, 'OLDG940321MOCRNN07', 'VANESSA', 'CRUZ', 'GONZALEZ', '1111111111', 'TIA', primary: true);
        $secondary = $this->link($student, 'SECG900101MDFRNS08', 'LAURA', 'MORA', 'DIAZ', '2222222222', 'TIA', secondary: true);

        $this->path = $this->writeBook([[
            'curp' => 'BECA131211HOCTRRA7',
            'g_name' => 'JUAN',
            'g_paterno' => 'MARTINEZ',
            'g_materno' => 'LOPEZ',
            'g_curp' => 'MALJ800101HOCRPR09',
            'phone' => '9512441693',
            'kinship' => 'PADRE',
            'street' => 'REFORMA',
            'exterior' => '20',
            'settlement' => 'CENTRO',
        ]]);

        $preview = app(MigrateSecondPrimaryFromDirectoryService::class)->preview($this->path, $year->id);
        $this->assertCount(1, $preview['plans']);
        $this->assertSame(0, $preview['applied']);
        $this->assertSame('parcial', $preview['plans'][0]['completeness']);
        $this->assertContains('tipo de vialidad', $preview['plans'][0]['missing']);
        $this->assertContains('municipio', $preview['plans'][0]['missing']);
        $this->assertSame('VANESSA', $primary->profile->fresh()->first_name);

        $applied = app(MigrateSecondPrimaryFromDirectoryService::class)->apply($this->path, $year->id);
        $this->assertSame(1, $applied['applied']);

        $profile = $primary->profile->fresh();
        $this->assertSame('JUAN', $profile->first_name);
        $this->assertSame('MARTINEZ', $profile->last_name);
        $this->assertSame('LOPEZ', $profile->second_last_name);
        $this->assertSame('MALJ800101HOCRPR09', $profile->national_id);
        $this->assertSame('9512441693', $profile->phone_number);
        $student->refresh()->load('profile.address', 'guardians');
        $this->assertSame('REFORMA', $student->profile->address->street_name);
        $this->assertSame($student->profile->address_id, $profile->fresh()->address_id);
        $this->assertSame('PADRE', $student->guardians->firstWhere('id', $primary->id)->pivot->relationship);
        $this->assertTrue((bool) $student->guardians->firstWhere('id', $primary->id)->pivot->is_primary);
        $this->assertSame('LAURA', $secondary->profile->fresh()->first_name);
        $this->assertTrue((bool) $student->guardians->firstWhere('id', $secondary->id)->pivot->is_secondary);
    }

    public function test_updates_tutor_matched_by_curp_even_if_linked_to_another_grade(): void
    {
        [$year, $second] = $this->period('2°');
        $thirdGrade = GradeLevel::query()->firstOrCreate(['name' => '3°']);
        $thirdGroup = ClassGroup::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $thirdGrade->id,
            'name' => 'A',
        ]);

        $secondStudent = $this->makeStudent('BECA131211HOCTRRA7', 'ARTURO', 'BETANZOS');
        $this->enroll($secondStudent, $year, $second, '2°');
        $oldPrimary = $this->link($secondStudent, 'OLDG940321MOCRNN07', 'VANESSA', 'CRUZ', 'GONZALEZ', '1111111111', 'MADRE', primary: true);

        $thirdStudent = $this->makeStudent('TERC131211HOCTRRA1', 'OTRO', 'ALUMNO');
        Enrollment::query()->create([
            'student_id' => $thirdStudent->id,
            'class_group_id' => $thirdGroup->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
            'is_new_admission' => false,
        ]);
        $shared = $this->link($thirdStudent, 'MALJ800101HOCRPR09', 'JUAN', 'VIEJO', 'APELLIDO', '9510000000', 'PADRE', primary: true);

        $this->path = $this->writeBook([[
            'curp' => 'BECA131211HOCTRRA7',
            'g_name' => 'JUAN CARLOS',
            'g_paterno' => 'MARTINEZ',
            'g_materno' => 'LOPEZ',
            'g_curp' => 'MALJ800101HOCRPR09',
            'phone' => '9512441693',
            'kinship' => 'PADRE',
            'street' => 'REFORMA',
        ]]);

        $result = app(MigrateSecondPrimaryFromDirectoryService::class)->apply($this->path, $year->id);
        $this->assertSame(1, $result['applied']);
        $this->assertSame([], $result['pending_first']);

        $shared->profile->refresh();
        $this->assertSame('JUAN CARLOS', $shared->profile->first_name);
        $this->assertSame('MARTINEZ', $shared->profile->last_name);
        $this->assertSame('9512441693', $shared->profile->phone_number);

        $secondStudent->refresh()->load('guardians');
        $this->assertTrue((bool) $secondStudent->guardians->firstWhere('id', $shared->id)->pivot->is_primary);
        $this->assertFalse((bool) $secondStudent->guardians->firstWhere('id', $oldPrimary->id)->pivot->is_primary);
        $this->assertSame('VANESSA', $oldPrimary->profile->fresh()->first_name);
    }

    public function test_tutor_linked_to_first_grade_is_pending_and_secondary_match_is_skipped(): void
    {
        [$year, $second] = $this->period('2°');
        $firstGrade = GradeLevel::query()->firstOrCreate(['name' => '1°']);
        $firstGroup = ClassGroup::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $firstGrade->id,
            'name' => 'A',
        ]);

        $fromFirst = $this->makeStudent('PRIM131211HOCTRRA2', 'NINO', 'PRIMERO');
        Enrollment::query()->create([
            'student_id' => $fromFirst->id,
            'class_group_id' => $firstGroup->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
            'is_new_admission' => false,
        ]);
        $firstTutor = $this->link($fromFirst, 'MALJ800101HOCRPR09', 'JUAN', 'MARTINEZ', 'LOPEZ', '9512441693', 'PADRE', primary: true);

        $student = $this->makeStudent('BECA131211HOCTRRA7', 'ARTURO', 'BETANZOS');
        $this->enroll($student, $year, $second, '2°');
        $primary = $this->link($student, 'OLDG940321MOCRNN07', 'VANESSA', 'CRUZ', 'GONZALEZ', '1111111111', 'MADRE', primary: true);

        $other = $this->makeStudent('OTRO131211HOCTRRA3', 'SOFIA', 'PEREZ');
        $this->enroll($other, $year, $second, '2°');
        $this->link($other, 'SECG900101MDFRNS08', 'LAURA', 'MORA', 'DIAZ', '2222222222', 'MADRE', primary: true);
        $secondary = $this->link($other, 'TIOC880202HOCRRS04', 'CARLOS', 'PEREZ', 'RUIZ', '3333333333', 'TIO', secondary: true);

        $this->path = $this->writeBook([
            [
                'curp' => 'BECA131211HOCTRRA7',
                'g_name' => 'JUAN',
                'g_paterno' => 'MARTINEZ',
                'g_materno' => 'LOPEZ',
                'g_curp' => 'MALJ800101HOCRPR09',
                'phone' => '9512441693',
                'kinship' => 'PADRE',
            ],
            [
                'curp' => 'OTRO131211HOCTRRA3',
                'g_name' => 'CARLOS',
                'g_paterno' => 'PEREZ',
                'g_materno' => 'RUIZ',
                'g_curp' => 'TIOC880202HOCRRS04',
                'phone' => '9510000000',
                'kinship' => 'TIO',
            ],
        ]);

        $result = app(MigrateSecondPrimaryFromDirectoryService::class)->apply($this->path, $year->id);

        $this->assertSame(0, $result['applied']);
        $this->assertCount(1, $result['pending_first']);
        $this->assertCount(1, $result['skipped_secondary']);
        $this->assertSame('JUAN', $firstTutor->profile->fresh()->first_name);
        $this->assertSame('VANESSA', $primary->profile->fresh()->first_name);
        $this->assertSame('3333333333', $secondary->profile->fresh()->phone_number);
    }

    public function test_creates_primary_when_the_student_has_none(): void
    {
        [$year, $group] = $this->period('2°');
        $student = $this->makeStudent('BECA131211HOCTRRA7', 'ARTURO', 'BETANZOS');
        $this->enroll($student, $year, $group, '2°');

        $this->path = $this->writeBook([[
            'curp' => 'BECA131211HOCTRRA7',
            'g_name' => 'JUAN',
            'g_paterno' => 'MARTINEZ',
            'g_materno' => 'LOPEZ',
            'g_curp' => 'MALJ800101HOCRPR09',
            'phone' => '9512441693',
            'kinship' => 'PADRE',
        ]]);

        $result = app(MigrateSecondPrimaryFromDirectoryService::class)->apply($this->path, $year->id);
        $this->assertSame(1, $result['applied']);
        $guardian = $student->fresh()->guardians()->first();
        $this->assertSame('MALJ800101HOCRPR09', $guardian->profile->national_id);
        $this->assertTrue((bool) $guardian->pivot->is_primary);
    }

    public function test_marks_row_completo_when_all_expected_fields_are_present(): void
    {
        [$year, $group] = $this->period('2°');
        $student = $this->makeStudent('BECA131211HOCTRRA7', 'ARTURO', 'BETANZOS');
        $this->enroll($student, $year, $group, '2°');

        $this->path = $this->writeBook([[
            'curp' => 'BECA131211HOCTRRA7',
            'g_name' => 'JUAN',
            'g_paterno' => 'MARTINEZ',
            'g_materno' => 'LOPEZ',
            'g_curp' => 'MALJ800101HOCRPR09',
            'phone' => '9512441693',
            'kinship' => 'PADRE',
            'street_type' => 'CALLE',
            'street' => 'REFORMA',
            'exterior' => '20',
            'settlement_type' => 'COLONIA',
            'settlement' => 'CENTRO',
            'city' => 'Oaxaca',
        ]]);

        $preview = app(MigrateSecondPrimaryFromDirectoryService::class)->preview($this->path, $year->id);
        $this->assertSame('completo', $preview['plans'][0]['completeness']);
        $this->assertSame([], $preview['plans'][0]['missing']);
    }

    /**
     * @return array{0: AcademicYear, 1: ClassGroup}
     */
    private function period(string $gradeName): array
    {
        $year = AcademicYear::factory()->create([
            'is_active' => true,
            'year_start' => '2026',
            'year_end' => '2027',
        ]);
        $grade = GradeLevel::query()->firstOrCreate(['name' => $gradeName]);
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

    private function enroll(Student $student, AcademicYear $year, ClassGroup $group, string $gradeName): void
    {
        Enrollment::query()->create([
            'student_id' => $student->id,
            'class_group_id' => $group->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
            'is_new_admission' => false,
        ]);
    }

    private function link(
        Student $student,
        string $curp,
        string $first,
        string $paterno,
        string $materno,
        string $phone,
        string $kinship,
        bool $primary = false,
        bool $secondary = false,
    ): Guardian {
        $profile = Profile::query()->create([
            'national_id' => $curp,
            'first_name' => $first,
            'last_name' => $paterno,
            'second_last_name' => $materno,
            'gender' => 'F',
            'phone_number' => $phone,
        ]);
        $guardian = Guardian::query()->create(['profile_id' => $profile->id, 'Kinship' => $kinship]);
        $student->guardians()->attach($guardian->id, [
            'relationship' => $kinship,
            'is_primary' => $primary,
            'is_secondary' => $secondary,
            'sort_order' => $primary ? 0 : 1,
        ]);

        return $guardian;
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function writeBook(array $rows): string
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'migrate-second-'.uniqid().'.xlsx';
        $sheet = new Spreadsheet();
        $active = $sheet->getActiveSheet();
        foreach ([
            'B3' => 'DATOS DEL ALUMNO',
            'T3' => 'TUTOR',
            'B4' => 'NOMBRE',
            'C4' => 'APELLIDO PATERNO',
            'F4' => 'CURP DEL ASPIRANTE',
            'G4' => 'VIALIDAD',
            'H4' => 'NOMBRE DE LA VIALIDAD',
            'I4' => 'NUMERO EXTERIOR',
            'J4' => 'ASENTAMIENTO',
            'K4' => 'NOMBRE DEL ASENTAMIENTO',
            'L4' => 'MUNICIPIO',
            'T4' => 'APELLIDO PATERNO',
            'U4' => 'APELLIDO MATERNO',
            'V4' => 'NOMBRE',
            'W4' => 'CURP TUTOR',
            'X4' => 'NÚMERO DE CONTACTO',
            'Y4' => 'PARENTESCO DEL ALUMNO',
        ] as $cell => $text) {
            $active->setCellValue($cell, $text);
        }
        foreach ($rows as $index => $row) {
            $r = $index + 5;
            $active->setCellValueExplicit('F'.$r, $row['curp'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('G'.$r, $row['street_type'] ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('H'.$r, $row['street'] ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('I'.$r, $row['exterior'] ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('J'.$r, $row['settlement_type'] ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('K'.$r, $row['settlement'] ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('L'.$r, $row['city'] ?? '', \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('T'.$r, $row['g_paterno'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('U'.$r, $row['g_materno'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('V'.$r, $row['g_name'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('W'.$r, $row['g_curp'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('X'.$r, $row['phone'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            $active->setCellValueExplicit('Y'.$r, $row['kinship'], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }
        (new Xlsx($sheet))->save($path);

        return $path;
    }
}
