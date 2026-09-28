<?php

namespace Tests\Feature\Admission;

use App\Console\Commands\temporal\ApplyFirstGradeRosterService;
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

    public function test_excel_wins_phone_and_email_and_adds_a_new_tutor(): void
    {
        [$year, $group] = $this->period();

        $student = $this->makeStudent('AEHV140919MOCRRNA0', 'VANIA ZOE', 'ARELLANES HERNANDEZ', [
            'phone_number' => '9511111111',
            'gender' => 'F',
            'birth_date' => '2014-09-19',
        ]);
        $this->enroll($student, $year, $group);
        $oldTutor = $this->linkTutor($student, 'HEPF781003MOCRRL06', 'FLOR', 'HERNANDEZ PEREZ', '9511111111', 'viejo@correo.com');

        $otherYear = AcademicYear::factory()->create([
            'is_active' => true,
            'year_start' => '2025',
            'year_end' => '2026',
        ]);
        $otherGrade = GradeLevel::query()->firstOrCreate(['name' => '1°']);
        $otherGroup = ClassGroup::query()->create([
            'academic_year_id' => $otherYear->id,
            'grade_level_id' => $otherGrade->id,
            'name' => 'A',
        ]);
        $outside = $this->makeStudent('ZZZZ140101HOCRRNA9', 'FUERA', 'DEL CICLO');
        $this->enroll($outside, $otherYear, $otherGroup);

        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sync-dir-details.xlsx';
        $this->writeDirectory($this->path, [
            [
                'first' => 'VANIA ZOE',
                'paterno' => 'ARELLANES',
                'materno' => 'HERNANDEZ',
                'curp' => 'AEHV140919MOCRRNA0',
                'g_paterno' => 'MARTINEZ',
                'g_materno' => 'LOPEZ',
                'g_name' => 'JUAN CARLOS',
                'g_curp' => 'MALJ800101HOCRPR09',
                'phone' => '9512441693',
                'email' => 'tutor.nuevo@escuela.mx',
                'kinship' => 'PADRE',
            ],
            [
                'first' => 'FUERA',
                'paterno' => 'DEL',
                'materno' => 'CICLO',
                'curp' => 'ZZZZ140101HOCRRNA9',
                'g_paterno' => 'X',
                'g_materno' => 'Y',
                'g_name' => 'NADIE',
                'g_curp' => 'NADA800101MOCRRLA1',
                'phone' => '9510000000',
                'email' => 'no@aplica.com',
                'kinship' => 'MADRE',
            ],
        ]);

        $summary = app(SyncDirectoryDetailsService::class)->sync($this->path, $year->id, '1°', dryRun: false);

        $this->assertSame([], $summary['errors']);
        $this->assertSame(1, $summary['matched']);
        $this->assertNotEmpty($summary['phone_changes']);
        $this->assertSame('9512441693', $summary['phone_changes'][0]['to']);
        $this->assertSame('tutor.nuevo@escuela.mx', $summary['email_changes'][0]['to']);
        $this->assertCount(1, $summary['tutors_added']);
        $this->assertSame('JUAN CARLOS MARTINEZ LOPEZ', $summary['tutors_added'][0]['tutor']);

        $student->refresh();
        $student->load('profile', 'guardians.profile');
        $this->assertSame('AEHV140919MOCRRNA0', $student->profile->national_id);
        $this->assertSame('F', $student->profile->gender);
        $this->assertSame('2014-09-19', substr((string) $student->profile->birth_date, 0, 10));
        $this->assertSame('9512441693', $student->profile->phone_number);
        $this->assertTrue($student->guardians->contains('id', $oldTutor->id));
        $this->assertSame(2, $student->guardians->count());
        $new = $student->guardians->first(fn (Guardian $guardian) => $guardian->id !== $oldTutor->id);
        $this->assertSame('MALJ800101HOCRPR09', $new?->profile?->national_id);
        $this->assertSame('9512441693', $new?->profile?->phone_number);
        $this->assertSame('tutor.nuevo@escuela.mx', $new?->profile?->email);
        $this->assertSame('viejo@correo.com', $oldTutor->profile->fresh()->email);
        $this->assertSame('9511111111', $oldTutor->profile->fresh()->phone_number);
        $this->assertNull($outside->profile->fresh()->phone_number);
    }

    public function test_same_tutor_updates_phone_without_creating_another(): void
    {
        [$year, $group] = $this->period();
        $student = $this->makeStudent('BOMA141106HOCHCLA3', 'ALAN LEONARDO', 'BOHORQUEZ MOCTEZUMA', [
            'phone_number' => '1111111111',
        ]);
        $this->enroll($student, $year, $group);
        $tutor = $this->linkTutor($student, 'MOAC890909MOCCMN00', 'MARIA CANDELARIA', 'MOCTEZUMA AMBROSIO', '1111111111', 'antes@correo.com');

        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sync-dir-same.xlsx';
        $this->writeDirectory($this->path, [[
            'first' => 'ALAN LEONARDO',
            'paterno' => 'BOHORQUEZ',
            'materno' => 'MOCTEZUMA',
            'curp' => 'BOMA141106HOCHCLA3',
            'g_paterno' => 'MOCTEZUMA',
            'g_materno' => 'AMBROSIO',
            'g_name' => 'MARIA CANDELARIA',
            'g_curp' => 'MOAC890909MOCCMN00',
            'phone' => '9512091247',
            'email' => 'ahora@correo.com',
            'kinship' => 'MADRE',
        ]]);

        $dry = app(SyncDirectoryDetailsService::class)->sync($this->path, $year->id, '1°', dryRun: true);
        $this->assertSame(0, $dry['applied']);
        $this->assertSame('1111111111', $tutor->profile->fresh()->phone_number);

        $summary = app(SyncDirectoryDetailsService::class)->sync($this->path, $year->id, '1°', dryRun: false);
        $this->assertSame([], $summary['tutors_added']);
        $this->assertSame(1, $student->guardians()->count());
        $this->assertSame('9512091247', $tutor->profile->fresh()->phone_number);
        $this->assertSame('ahora@correo.com', $tutor->profile->fresh()->email);
        $this->assertSame('9512091247', $student->profile->fresh()->phone_number);
    }

    public function test_refuses_years_other_than_2026_2027(): void
    {
        $year = AcademicYear::factory()->create([
            'is_active' => true,
            'year_start' => '2025',
            'year_end' => '2026',
        ]);
        GradeLevel::query()->create(['name' => '1°']);
        $this->path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'sync-dir-year.xlsx';
        $this->writeDirectory($this->path, []);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('2026-2027');
        app(SyncDirectoryDetailsService::class)->sync($this->path, $year->id, '1°', dryRun: true);
    }

    /**
     * @return array{0: AcademicYear, 1: ClassGroup}
     */
    private function period(): array
    {
        $year = AcademicYear::factory()->create([
            'is_active' => false,
            'year_start' => '2026',
            'year_end' => '2027',
        ]);
        $grade = GradeLevel::query()->firstOrCreate(['name' => '1°']);
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
            'is_new_admission' => true,
            'admission_channel' => 'late',
            'placement_status' => 'placed',
        ]);
    }

    private function linkTutor(
        Student $student,
        string $curp,
        string $first,
        string $last,
        string $phone,
        string $email,
    ): Guardian {
        $profile = Profile::query()->create([
            'national_id' => $curp,
            'first_name' => $first,
            'last_name' => $last,
            'gender' => 'F',
            'phone_number' => $phone,
            'email' => $email,
        ]);
        $guardian = Guardian::query()->create([
            'profile_id' => $profile->id,
            'Kinship' => 'MADRE',
        ]);
        $student->guardians()->syncWithoutDetaching([
            $guardian->id => ['relationship' => 'MADRE'],
        ]);

        return $guardian;
    }

    /**
     * @param  list<array<string, string>>  $rows
     */
    private function writeDirectory(string $path, array $rows): void
    {
        $sheet = new Spreadsheet();
        $active = $sheet->getActiveSheet();
        $active->setTitle(ApplyFirstGradeRosterService::SHEET);
        $active->setCellValue('G4', 'CURP DEL ASPIRANTE');
        $active->setCellValue('AA4', 'CORREO');
        foreach ($rows as $index => $row) {
            $r = $index + 5;
            $active->setCellValue('C'.$r, $row['first']);
            $active->setCellValue('D'.$r, $row['paterno']);
            $active->setCellValue('E'.$r, $row['materno']);
            $active->setCellValue('G'.$r, $row['curp']);
            $active->setCellValue('H'.$r, 'A');
            $active->setCellValue('O'.$r, 'CALLE');
            $active->setCellValue('P'.$r, 'EMILIANO ZAPATA');
            $active->setCellValue('Q'.$r, '402');
            $active->setCellValue('S'.$r, 'COLONIA');
            $active->setCellValue('T'.$r, 'DOLORES');
            $active->setCellValue('U'.$r, $row['g_paterno']);
            $active->setCellValue('V'.$r, $row['g_materno']);
            $active->setCellValue('W'.$r, $row['g_name']);
            $active->setCellValue('X'.$r, $row['g_curp']);
            $active->setCellValue('Y'.$r, $row['phone']);
            $active->setCellValue('Z'.$r, $row['kinship']);
            $active->setCellValue('AA'.$r, $row['email']);
        }

        (new Xlsx($sheet))->save($path);
    }
}
