<?php

namespace Tests\Feature\Admission;

use App\Console\Commands\temporal\SyncDirectoryDetailsService;
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

class SyncDirectoryDetailsServiceTest extends TestCase
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

    public function test_excel_wins_tutor_phone_email_and_address_and_adds_a_new_tutor(): void
    {
        [$year, $group] = $this->period('1°');

        $student = $this->makeStudent('AEHV140919MOCRRNA0', 'VANIA ZOE', 'ARELLANES HERNANDEZ', [
            'phone_number' => '9511111111',
            'gender' => 'F',
            'birth_date' => '2014-09-19',
        ]);
        $this->enroll($student, $year, $group);
        $oldTutor = $this->linkTutor($student, 'HEPF781003MOCRRL06', 'FLOR', 'HERNANDEZ PEREZ', '9511111111', 'viejo@correo.com');

        $otherYear = AcademicYear::factory()->create(['is_active' => true, 'year_start' => '2025', 'year_end' => '2026']);
        $otherGroup = ClassGroup::query()->create([
            'academic_year_id' => $otherYear->id,
            'grade_level_id' => $group->grade_level_id,
            'name' => 'A',
        ]);
        $outside = $this->makeStudent('ZZZZ140101HOCRRNA9', 'FUERA', 'DEL CICLO');
        $this->enroll($outside, $otherYear, $otherGroup);

        $this->path = $this->tempPath('sync-dir-details');
        $this->writeSplitDirectory($this->path, [
            $this->row('VANIA ZOE', 'ARELLANES', 'HERNANDEZ', 'AEHV140919MOCRRNA0', [
                'g_paterno' => 'MARTINEZ',
                'g_materno' => 'LOPEZ',
                'g_name' => 'JUAN CARLOS',
                'g_curp' => 'MALJ800101HOCRPR09',
                'phone' => '9512441693',
                'email' => 'tutor.nuevo@escuela.mx',
                'kinship' => 'PADRE',
                'street' => 'REFORMA',
                'exterior' => '20',
                'settlement' => 'CENTRO',
            ]),
            $this->row('FUERA', 'DEL', 'CICLO', 'ZZZZ140101HOCRRNA9', ['phone' => '9510000000']),
        ]);

        $summary = app(SyncDirectoryDetailsService::class)->sync($this->path, $year->id, '1°', dryRun: false);

        $this->assertSame([], $summary['errors']);
        $this->assertSame(1, $summary['matched']);
        $this->assertSame([], $summary['curp_changes']);
        $this->assertSame('9512441693', $summary['phone_changes'][0]['to']);
        $this->assertSame('tutor.nuevo@escuela.mx', $summary['email_changes'][0]['to']);
        $this->assertCount(1, $summary['tutors_added']);
        $this->assertCount(1, $summary['address_changes']);

        $student->refresh()->load('profile.address', 'guardians.profile');
        $this->assertSame('AEHV140919MOCRRNA0', $student->profile->national_id);
        $this->assertSame('9511111111', $student->profile->phone_number);
        $this->assertSame('REFORMA', $student->profile->address->street_name);
        $this->assertSame('20', $student->profile->address->house_number);
        $this->assertSame('CENTRO', $student->profile->address->neighborhood_name);
        $this->assertSame(2, $student->guardians->count());
        $new = $student->guardians->first(fn (Guardian $guardian) => $guardian->id !== $oldTutor->id);
        $this->assertSame('MALJ800101HOCRPR09', $new?->profile?->national_id);
        $this->assertSame('9512441693', $new?->profile?->phone_number);
        $this->assertSame('tutor.nuevo@escuela.mx', $new?->profile?->email);
        $this->assertSame('9511111111', $oldTutor->profile->fresh()->phone_number);
        $this->assertNull($outside->profile->fresh()->phone_number);
    }

    public function test_same_tutor_by_name_gets_excel_phone_without_duplicate(): void
    {
        [$year, $group] = $this->period('2°');
        $student = $this->makeStudent('BECA131211HOCTRRA7', 'ARTURO ALEJANDRO', 'BETANZOS CRUZ');
        $this->enroll($student, $year, $group);
        $tutor = $this->linkTutor($student, 'TUTBECA131211HOC01', 'VANESSA', 'CRUZ GONZALEZ', '1111111111', null);

        $this->path = $this->tempPath('sync-dir-same');
        $this->writeSplitDirectory($this->path, [
            $this->row('ARTURO ALEJANDRO', 'BETANZOS', 'CRUZ', 'BECA131211HOCTRRA7', [
                'g_paterno' => 'CRUZ',
                'g_materno' => 'GONZALEZ',
                'g_name' => 'VANESSA',
                'phone' => '951 312 1405, 9512902292',
                'kinship' => 'MADRE',
            ]),
        ], withTutorCurp: false);

        $dry = app(SyncDirectoryDetailsService::class)->sync($this->path, $year->id, '2°', dryRun: true);
        $this->assertSame(0, $dry['applied']);
        $this->assertSame('1111111111', $tutor->profile->fresh()->phone_number);

        $summary = app(SyncDirectoryDetailsService::class)->sync($this->path, $year->id, '2°', dryRun: false);
        $this->assertSame([], $summary['tutors_added']);
        $this->assertSame(1, $student->guardians()->count());
        $this->assertSame('9513121405', $tutor->profile->fresh()->phone_number);
    }

    public function test_near_curp_takes_the_excel_curp_and_its_birth_date(): void
    {
        [$year, $group] = $this->period('2°');
        $student = $this->makeStudent('BOMA130302HDFYRGA8', 'AGUSTIN', 'BOYSO MARTINEZ', [
            'birth_date' => '2013-03-03',
        ]);
        $this->enroll($student, $year, $group);

        $this->path = $this->tempPath('sync-dir-near');
        $this->writeSplitDirectory($this->path, [
            $this->row('AGUSTIN', 'BOYSO', 'MARTINEZ', 'BOMA130302HDFYRGA9', ['phone' => '5626061573']),
        ], withTutorCurp: false);

        $summary = app(SyncDirectoryDetailsService::class)->sync($this->path, $year->id, '2°', dryRun: false);

        $this->assertSame([], $summary['errors']);
        $this->assertSame('BOMA130302HDFYRGA8', $summary['curp_changes'][0]['from']);
        $this->assertSame('BOMA130302HDFYRGA9', $summary['curp_changes'][0]['to']);
        $profile = $student->profile->fresh();
        $this->assertSame('BOMA130302HDFYRGA9', $profile->national_id);
        $this->assertSame('2013-03-02', substr((string) $profile->birth_date, 0, 10));
        $this->assertSame('M', $profile->gender);
    }

    public function test_full_name_sheet_matches_by_name_and_keeps_db_curp_when_excel_curp_is_taken(): void
    {
        [$year, $group] = $this->period('3°');
        $sofia = $this->makeStudent('XXXX120301MOCVSFA3', 'SOFIA CONSTANZA', 'AVENDAÑO RIOS');
        $this->enroll($sofia, $year, $group);
        $amber = $this->makeStudent('YYYY120101MOCRRMA7', 'AMBER MAYTHE', 'BERNARDO PERALTA');
        $this->enroll($amber, $year, $group);
        $this->makeStudent('BEPA120101MOCRRMA7', 'AMBER', 'DUPLICADA');

        $this->path = $this->tempPath('sync-dir-third');
        $this->writeThirdGradeDirectory($this->path, [
            ['AVENDAÑO RIOS SOFIA CONSTANZA', 'AERS120301MOCVSFA3', 'RIOS', 'GONZALEZ', 'CONSTANZA', '9514780693'],
            ['BERNARDO PERALTA AMBER MAYTHE', 'BEPA120101MOCRRMA7', 'PERALTA', 'DIEGO', 'KARINA FRANCISCA', '9516469824'],
        ]);

        $summary = app(SyncDirectoryDetailsService::class)->sync($this->path, $year->id, '3°', dryRun: false);

        $this->assertSame([], $summary['errors']);
        $this->assertSame(2, $summary['matched']);
        $this->assertCount(1, $summary['curp_changes']);
        $this->assertSame('AERS120301MOCVSFA3', $sofia->profile->fresh()->national_id);
        $this->assertSame('YYYY120101MOCRRMA7', $amber->profile->fresh()->national_id);
        $this->assertTrue(collect($summary['skipped'])->contains(fn (array $row) => str_contains($row['reason'], 'posible duplicado')));
        $this->assertCount(2, $summary['tutors_added']);
        $this->assertSame('9514780693', $sofia->guardians()->first()?->profile?->phone_number);
        $this->assertSame('LOS RIOS', $sofia->profile->fresh()->address->neighborhood_name);
    }

    public function test_refuses_years_other_than_2026_2027(): void
    {
        $year = AcademicYear::factory()->create(['is_active' => true, 'year_start' => '2025', 'year_end' => '2026']);
        GradeLevel::query()->create(['name' => '1°']);
        $this->path = $this->tempPath('sync-dir-year');
        $this->writeSplitDirectory($this->path, []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('2026-2027');
        app(SyncDirectoryDetailsService::class)->sync($this->path, $year->id, '1°', dryRun: true);
    }

    /**
     * @return array{0: AcademicYear, 1: ClassGroup}
     */
    private function period(string $gradeName): array
    {
        $year = AcademicYear::factory()->create(['is_active' => false, 'year_start' => '2026', 'year_end' => '2027']);
        $grade = GradeLevel::query()->firstOrCreate(['name' => $gradeName]);
        $group = ClassGroup::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'name' => 'A',
        ]);

        return [$year, $group];
    }

    /**
     * @param  array<string, mixed>  $profile
     */
    private function makeStudent(string $curp, string $first, string $last, array $profile = []): Student
    {
        $address = Address::query()->create([
            'street_type' => 'CALLE',
            'street_name' => 'EMILIANO ZAPATA',
            'house_number' => '1',
            'neighborhood_type' => 'COLONIA',
            'neighborhood_name' => 'DOLORES',
            'postal_code' => '68000',
            'city' => 'Oaxaca de Juárez',
            'state' => 'Oaxaca',
        ]);
        $created = Profile::query()->create(array_merge([
            'national_id' => $curp,
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'M',
            'birth_date' => '2014-01-01',
            'address_id' => $address->id,
        ], $profile));

        return Student::query()->create(['profile_id' => $created->id]);
    }

    private function enroll(Student $student, AcademicYear $year, ClassGroup $group): Enrollment
    {
        return Enrollment::query()->create([
            'student_id' => $student->id,
            'class_group_id' => $group->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
            'is_new_admission' => false,
        ]);
    }

    private function linkTutor(Student $student, string $curp, string $first, string $last, string $phone, ?string $email): Guardian
    {
        $profile = Profile::query()->create([
            'national_id' => $curp,
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'F',
            'phone_number' => $phone,
            'email' => $email,
        ]);
        $guardian = Guardian::query()->create(['profile_id' => $profile->id, 'Kinship' => 'MADRE']);
        $student->guardians()->syncWithoutDetaching([$guardian->id => ['relationship' => 'MADRE']]);

        return $guardian;
    }

    /**
     * @param  array<string, string>  $extra
     * @return array<string, string>
     */
    private function row(string $first, string $paterno, string $materno, string $curp, array $extra = []): array
    {
        return array_merge([
            'first' => $first,
            'paterno' => $paterno,
            'materno' => $materno,
            'curp' => $curp,
            'street_type' => 'CALLE',
            'street' => 'EMILIANO ZAPATA',
            'interior' => '',
            'exterior' => '1',
            'settlement_type' => 'COLONIA',
            'settlement' => 'DOLORES',
            'g_paterno' => '',
            'g_materno' => '',
            'g_name' => '',
            'g_curp' => '',
            'phone' => '',
            'email' => '',
            'kinship' => '',
        ], $extra);
    }

    private function tempPath(string $name): string
    {
        return sys_get_temp_dir().DIRECTORY_SEPARATOR.$name.'-'.uniqid().'.xlsx';
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function writeSplitDirectory(string $path, array $rows, bool $withTutorCurp = true): void
    {
        $sheet = new Spreadsheet();
        $active = $sheet->getActiveSheet();
        $active->setTitle('DIRECTORIO');
        $sections = ['B3' => 'ALUMNO', 'N3' => 'DIRECCIÓN', 'T3' => 'TUTOR', 'Y3' => 'NÚMERO DE CONTACTO', 'Z3' => 'PARENTESCO DEL ALUMNO', 'AA3' => 'CORREO'];
        $headers = [
            'B4' => 'NOMBRE', 'C4' => 'APELLIDO PATERNO', 'D4' => 'APELLIDO MATERNO', 'F4' => 'CURP DEL ASPIRANTE', 'G4' => 'GRUPO',
            'N4' => 'VIALIDAD', 'O4' => 'NOMBRE DE LA VIALIDAD', 'P4' => 'NÚMERO INTERIOR', 'Q4' => 'NÚMERO EXTERIOR',
            'R4' => 'ASENTAMIENTO', 'S4' => 'NOMBRE DEL ASENTAMIENTO',
            'T4' => 'APELLIDO PATERNO', 'U4' => 'APELLIDO MATERNO', 'V4' => 'NOMBRE',
        ];
        if ($withTutorCurp) {
            $headers['W4'] = 'CURP';
        }
        foreach ($sections + $headers as $cell => $text) {
            $active->setCellValue($cell, $text);
        }

        $columns = [
            'first' => 'B', 'paterno' => 'C', 'materno' => 'D', 'curp' => 'F',
            'street_type' => 'N', 'street' => 'O', 'interior' => 'P', 'exterior' => 'Q',
            'settlement_type' => 'R', 'settlement' => 'S',
            'g_paterno' => 'T', 'g_materno' => 'U', 'g_name' => 'V', 'g_curp' => 'W',
            'phone' => 'Y', 'kinship' => 'Z', 'email' => 'AA',
        ];
        foreach ($rows as $index => $row) {
            $r = $index + 5;
            $active->setCellValue('G'.$r, 'A');
            foreach ($columns as $key => $column) {
                if ($key === 'g_curp' && ! $withTutorCurp) {
                    continue;
                }
                $active->setCellValueExplicit($column.$r, $row[$key], \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
            }
        }

        (new Xlsx($sheet))->save($path);
    }

    /**
     * @param  list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>  $rows
     */
    private function writeThirdGradeDirectory(string $path, array $rows): void
    {
        $sheet = new Spreadsheet();
        $active = $sheet->getActiveSheet();
        $active->setTitle('DATOS GENERALES');
        $active->setCellValue('A2', 'DIRECTORIO DE TERCER GRADO CICLO ESCOLAR 2026-2027');
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
        foreach ($rows as $index => [$full, $curp, $gPaterno, $gMaterno, $gName, $phone]) {
            $r = $index + 6;
            $active->setCellValue('B'.$r, $full);
            $active->setCellValue('C'.$r, $curp);
            $active->setCellValue('D'.$r, 'A');
            $active->setCellValue('F'.$r, 'CALLE');
            $active->setCellValue('G'.$r, 'RIO ATOYAC');
            $active->setCellValue('I'.$r, '600');
            $active->setCellValue('J'.$r, 'FRACCIONAMIENTO');
            $active->setCellValue('K'.$r, 'LOS RIOS');
            $active->setCellValue('L'.$r, 'OAXACA DE JUAREZ');
            $active->setCellValue('M'.$r, $gPaterno);
            $active->setCellValue('N'.$r, $gMaterno);
            $active->setCellValue('O'.$r, $gName);
            $active->setCellValueExplicit('P'.$r, $phone, \PhpOffice\PhpSpreadsheet\Cell\DataType::TYPE_STRING);
        }

        (new Xlsx($sheet))->save($path);
    }
}
