<?php

namespace Tests\Feature\Workshops;

use App\Enums\AdmissionWorkshop;
use App\Enums\EnrollmentStatus;
use App\Enums\PromotionResult;
use App\Enums\WorkshopEnrollmentSource;
use App\Enums\WorkshopEnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\Attendance;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\PreEnrollment;
use App\Models\Profile;
use App\Models\Schedule;
use App\Models\SchoolClass;
use App\Models\Student;
use App\Models\Subject;
use App\Models\Teacher;
use App\Models\User;
use App\Models\Workshop;
use App\Models\WorkshopEnrollment;
use App\Models\WorkshopOffering;
use App\Services\AssignBulkGhWorkshopsService;
use App\Services\AssignStudentWorkshopService;
use App\Services\EnrollmentPromotionService;
use App\Services\FirstGradeWorkshopAssignmentService;
use App\Services\ImportSchedulesFromExcelService;
use App\Services\School\ReEnrollmentService;
use App\Enums\ReEnrollmentPeriodStatus;
use App\Enums\ReEnrollmentProcessStep;
use App\Models\School\ReEnrollmentPeriod;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class WorkshopDomainTest extends TestCase
{
    use RefreshDatabase;

    public function test_workshop_enrollment_is_unique_per_student_and_year(): void
    {
        [$year, $student] = $this->seedStudentInFirst();
        $workshops = $this->seedWorkshops($year, 10);

        WorkshopEnrollment::create([
            'student_id' => $student->id,
            'workshop_id' => $workshops['INFORMATICA']->id,
            'academic_year_id' => $year->id,
            'source' => WorkshopEnrollmentSource::FirstChoice,
            'status' => WorkshopEnrollmentStatus::Assigned,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        WorkshopEnrollment::create([
            'student_id' => $student->id,
            'workshop_id' => $workshops['DISENO']->id,
            'academic_year_id' => $year->id,
            'source' => WorkshopEnrollmentSource::Manual,
            'status' => WorkshopEnrollmentStatus::Assigned,
        ]);
    }

    public function test_lote_uses_second_choice_when_first_is_full(): void
    {
        [$year, $group] = $this->seedYearAndFirstGroup();
        $workshops = $this->seedWorkshops($year, 1);

        $this->makeFirstCandidate($year, $group, 'AAA', AdmissionWorkshop::Informatics->value, AdmissionWorkshop::IndustrialDesign->value, 9.5);
        $second = $this->makeFirstCandidate($year, $group, 'BBB', AdmissionWorkshop::Informatics->value, AdmissionWorkshop::IndustrialDesign->value, 8.0);

        $result = app(FirstGradeWorkshopAssignmentService::class)->run($year->id, dryRun: false);

        $this->assertSame(2, $result['summary']['total_candidates']);
        $row = collect($result['assignments'])->firstWhere('student_id', $second->student_id);
        $this->assertSame('second_choice', $row['source']);
        $this->assertSame($workshops['DISENO']->id, $row['workshop_id']);
    }

    public function test_lote_waitlists_when_both_choices_are_full(): void
    {
        [$year, $group] = $this->seedYearAndFirstGroup();
        $this->seedWorkshops($year, 1);

        $this->makeFirstCandidate($year, $group, 'A1', AdmissionWorkshop::Informatics->value, AdmissionWorkshop::IndustrialDesign->value, 10);
        $this->makeFirstCandidate($year, $group, 'A2', AdmissionWorkshop::IndustrialDesign->value, AdmissionWorkshop::Informatics->value, 9);
        $third = $this->makeFirstCandidate($year, $group, 'A3', AdmissionWorkshop::Informatics->value, AdmissionWorkshop::IndustrialDesign->value, 8);

        $result = app(FirstGradeWorkshopAssignmentService::class)->run($year->id, dryRun: false);

        $row = collect($result['assignments'])->firstWhere('student_id', $third->student_id);
        $this->assertContains('waitlisted', $row['flags']);
        $this->assertSame('waitlisted', $row['status']);
        $this->assertSame(
            WorkshopEnrollmentStatus::Waitlisted,
            WorkshopEnrollment::query()->where('student_id', $third->student_id)->first()?->status
        );
    }

    public function test_lote_does_not_overwrite_manual_assignment(): void
    {
        [$year, $group] = $this->seedYearAndFirstGroup();
        $workshops = $this->seedWorkshops($year, 10);
        $enrollment = $this->makeFirstCandidate($year, $group, 'MAN', AdmissionWorkshop::Informatics->value, AdmissionWorkshop::IndustrialDesign->value, 10);

        WorkshopEnrollment::create([
            'student_id' => $enrollment->student_id,
            'workshop_id' => $workshops['CONFECCION']->id,
            'academic_year_id' => $year->id,
            'source' => WorkshopEnrollmentSource::Manual,
            'status' => WorkshopEnrollmentStatus::Assigned,
        ]);

        $result = app(FirstGradeWorkshopAssignmentService::class)->run($year->id, dryRun: false);

        $this->assertSame(0, $result['summary']['total_candidates']);
        $this->assertSame(
            $workshops['CONFECCION']->id,
            WorkshopEnrollment::query()->where('student_id', $enrollment->student_id)->value('workshop_id')
        );
    }

    public function test_promotion_inherits_workshop_and_skips_manual_destination(): void
    {
        $from = AcademicYear::factory()->create(['is_active' => false]);
        $to = AcademicYear::factory()->create(['is_active' => true]);
        $g1 = GradeLevel::create(['name' => '1°']);
        $g2 = GradeLevel::create(['name' => '2°']);
        $fromGroup = ClassGroup::create(['academic_year_id' => $from->id, 'grade_level_id' => $g1->id, 'name' => 'A']);
        ClassGroup::create(['academic_year_id' => $to->id, 'grade_level_id' => $g2->id, 'name' => 'A']);

        $workshops = $this->seedWorkshops($from, 10);
        $this->seedWorkshops($to, 10);

        $student = $this->makeBareStudent('Ana', 'Perez');
        Enrollment::create([
            'student_id' => $student->id,
            'class_group_id' => $fromGroup->id,
            'academic_year_id' => $from->id,
            'status' => EnrollmentStatus::Active,
            'is_approved' => true,
        ]);
        WorkshopEnrollment::create([
            'student_id' => $student->id,
            'workshop_id' => $workshops['INFORMATICA']->id,
            'academic_year_id' => $from->id,
            'source' => WorkshopEnrollmentSource::FirstChoice,
            'status' => WorkshopEnrollmentStatus::Assigned,
        ]);

        $manualStudent = $this->makeBareStudent('Luis', 'Mora');
        Enrollment::create([
            'student_id' => $manualStudent->id,
            'class_group_id' => $fromGroup->id,
            'academic_year_id' => $from->id,
            'status' => EnrollmentStatus::Active,
            'is_approved' => true,
        ]);
        WorkshopEnrollment::create([
            'student_id' => $manualStudent->id,
            'workshop_id' => $workshops['INFORMATICA']->id,
            'academic_year_id' => $from->id,
            'source' => WorkshopEnrollmentSource::FirstChoice,
            'status' => WorkshopEnrollmentStatus::Assigned,
        ]);
        WorkshopEnrollment::create([
            'student_id' => $manualStudent->id,
            'workshop_id' => $workshops['DISENO']->id,
            'academic_year_id' => $to->id,
            'source' => WorkshopEnrollmentSource::Manual,
            'status' => WorkshopEnrollmentStatus::Assigned,
        ]);

        $summary = app(EnrollmentPromotionService::class)->promote($from->id, $to->id, false);

        $this->assertSame(1, $summary['workshops_inherited']);
        $this->assertSame(1, $summary['workshops_skipped_manual']);
        $dest = WorkshopEnrollment::query()
            ->where('student_id', $student->id)
            ->where('academic_year_id', $to->id)
            ->first();
        $this->assertSame(WorkshopEnrollmentSource::Inherited, $dest?->source);
        $this->assertSame($workshops['INFORMATICA']->id, $dest?->workshop_id);
        $this->assertSame(
            $workshops['DISENO']->id,
            WorkshopEnrollment::query()
                ->where('student_id', $manualStudent->id)
                ->where('academic_year_id', $to->id)
                ->value('workshop_id')
        );
        $this->assertSame(PromotionResult::PROMOTED, Enrollment::query()->where('academic_year_id', $to->id)->where('student_id', $student->id)->first()?->promotion_result);
    }

    public function test_manual_assign_respects_capacity_unless_forced(): void
    {
        [$year, $group] = $this->seedYearAndFirstGroup();
        $workshops = $this->seedWorkshops($year, 1);
        $first = $this->makeFirstCandidate($year, $group, 'C1', AdmissionWorkshop::Informatics->value, AdmissionWorkshop::IndustrialDesign->value, 9);
        $second = $this->makeFirstCandidate($year, $group, 'C2', AdmissionWorkshop::Informatics->value, AdmissionWorkshop::IndustrialDesign->value, 8);

        app(AssignStudentWorkshopService::class)->assign(
            student: Student::findOrFail($first->student_id),
            workshopId: $workshops['INFORMATICA']->id,
        );

        try {
            app(AssignStudentWorkshopService::class)->assign(
                student: Student::findOrFail($second->student_id),
                workshopId: $workshops['INFORMATICA']->id,
            );
            $this->fail('Expected over-capacity assignment to fail.');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('cupo', $e->getMessage());
        }

        $forced = app(AssignStudentWorkshopService::class)->assign(
            student: Student::findOrFail($second->student_id),
            workshopId: $workshops['INFORMATICA']->id,
            force: true,
        );
        $this->assertContains('over_capacity', $forced['warnings']);
        $this->assertSame($workshops['INFORMATICA']->id, $forced['enrollment']->workshop_id);
    }

    public function test_schedule_index_returns_workshop_slots_by_schedule_teacher(): void
    {
        $year = AcademicYear::factory()->create(['is_active' => true]);
        $grade = GradeLevel::create(['name' => '2°']);
        $group = ClassGroup::create(['academic_year_id' => $year->id, 'grade_level_id' => $grade->id, 'name' => 'A']);
        $subject = $this->tecnologiaSubject();
        $workshops = $this->seedWorkshops($year, 10);

        $teacherProfile = Profile::create([
            'national_id' => strtoupper(fake()->unique()->bothify('????######H?????##')),
            'first_name' => 'Berenice',
            'last_name' => 'Climaco',
            'gender' => 'F',
        ]);
        $teacher = Teacher::create(['profile_id' => $teacherProfile->id, 'status' => 'active']);

        $schoolClass = SchoolClass::create([
            'subject_id' => $subject->id,
            'class_group_id' => $group->id,
            'teacher_id' => null,
        ]);

        $schedule = Schedule::create([
            'school_class_id' => $schoolClass->id,
            'workshop_id' => $workshops['INFORMATICA']->id,
            'teacher_id' => $teacher->id,
            'day' => 'Lunes',
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'schedule_type' => 'workshop',
        ]);

        $user = User::factory()->create();
        $this->grantSchedulePermissions($user, ['view attendance', 'view all schedules']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/schedules?teacher_id='.$teacher->id);
        $response->assertOk();
        $this->assertSame($schedule->id, $response->json('schedules.0.id'));
        $this->assertSame('workshop', $response->json('schedules.0.schedule_type'));
        $this->assertSame('Informática', $response->json('schedules.0.subject'));
    }

    public function test_excel_import_creates_regular_and_workshop_schedules(): void
    {
        $year = AcademicYear::factory()->create(['is_active' => true]);
        $grade = GradeLevel::create(['name' => '2°']);
        ClassGroup::create(['academic_year_id' => $year->id, 'grade_level_id' => $grade->id, 'name' => 'A']);
        Subject::query()->firstOrCreate(['name' => 'Español']);
        $this->tecnologiaSubject();
        $workshops = $this->seedWorkshops($year, 10);

        $teacherProfile = Profile::create([
            'national_id' => strtoupper(fake()->unique()->bothify('????######H?????##')),
            'first_name' => 'Raymundo',
            'last_name' => 'Cruz',
            'gender' => 'M',
        ]);
        Teacher::create(['profile_id' => $teacherProfile->id, 'status' => 'active']);

        $grupos = $this->writeGruposFixture();
        $docentes = $this->writeDocentesFixture();

        $summary = app(ImportSchedulesFromExcelService::class)->import(
            academicYearId: $year->id,
            gruposPath: $grupos,
            docentesPath: $docentes,
            dryRun: false,
        );

        $this->assertGreaterThanOrEqual(2, $summary['created']);
        $this->assertTrue(Schedule::query()->where('schedule_type', 'regular')->exists());
        $this->assertTrue(
            Schedule::query()
                ->where('schedule_type', 'workshop')
                ->where('workshop_id', $workshops['DISENO']->id)
                ->exists()
        );
    }

    public function test_excel_import_reads_institutional_split_time_columns(): void
    {
        $year = AcademicYear::factory()->create(['is_active' => true]);
        $grade = GradeLevel::create(['name' => '1°']);
        ClassGroup::create(['academic_year_id' => $year->id, 'grade_level_id' => $grade->id, 'name' => 'A']);
        Subject::query()->firstOrCreate(['name' => 'Español']);
        Subject::query()->firstOrCreate(['name' => 'Formación Cívica y Ética']);
        $this->tecnologiaSubject();
        $workshops = $this->seedWorkshops($year, 10);

        $teacherProfile = Profile::create([
            'national_id' => strtoupper(fake()->unique()->bothify('????######H?????##')),
            'first_name' => 'Raymundo',
            'last_name' => 'Cruz Islas',
            'gender' => 'M',
        ]);
        Teacher::create(['profile_id' => $teacherProfile->id, 'status' => 'active']);

        $grupos = new Spreadsheet;
        $sheet = $grupos->getActiveSheet();
        $sheet->setTitle('1A');
        $sheet->setCellValue('A12', 'HORA');
        $sheet->setCellValue('C12', 'LUNES');
        $sheet->setCellValue('E12', 'HORA');
        $sheet->setCellValue('G12', 'MARTES');
        $sheet->setCellValue('H12', 'MIÉRCOLES');
        $sheet->setCellValue('I12', 'JUEVES');
        $sheet->setCellValue('J12', 'VIERNES');
        $sheet->setCellValue('A13', '7:00 - 7:45');
        $sheet->setCellValue('C13', 'FORM. CIV. Y ÉTICA');
        $sheet->setCellValue('E13', '7:00 - 7:50');
        $sheet->setCellValue('G13', 'ESPAÑOL');
        $sheet->setCellValue('A21', '10:15 - 11:00');
        $sheet->setCellValue('C21', 'TECNOLOGÍA');
        $gruposPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'grupos-institucional.xlsx';
        (new Xlsx($grupos))->save($gruposPath);

        $docentes = new Spreadsheet;
        $docSheet = $docentes->getActiveSheet();
        $docSheet->setTitle('Raymundo');
        $docSheet->setCellValue('L9', 'TALLER DE: Dibujo');
        $docSheet->setCellValue('A13', 'DISEÑO INDUSTRIAL');
        $docSheet->setCellValue('A17', 'HORA');
        $docSheet->setCellValue('C17', 'LUNES');
        $docSheet->setCellValue('E17', 'HORA');
        $docSheet->setCellValue('H17', 'MARTES');
        $docSheet->setCellValue('J17', 'MIÉRCOLES');
        $docSheet->setCellValue('L17', 'JUEVES');
        $docSheet->setCellValue('A18', '7:00 - 7:45');
        $docSheet->setCellValue('C18', '1 DEF');
        $docSheet->setCellValue('E18', '7:00 - 7:50');
        $docSheet->setCellValue('H18', '1 A');
        $docentesPath = sys_get_temp_dir().DIRECTORY_SEPARATOR.'docentes-institucional.xlsx';
        (new Xlsx($docentes))->save($docentesPath);

        $summary = app(ImportSchedulesFromExcelService::class)->import(
            academicYearId: $year->id,
            gruposPath: $gruposPath,
            docentesPath: $docentesPath,
            dryRun: false,
        );

        $this->assertGreaterThanOrEqual(2, $summary['planned']);
        $this->assertTrue(Schedule::query()->where('schedule_type', 'regular')->where('day', 'Lunes')->exists());
        $this->assertTrue(Schedule::query()->where('schedule_type', 'regular')->where('day', 'Martes')->exists());
        $this->assertTrue(
            Schedule::query()
                ->where('schedule_type', 'workshop')
                ->where('workshop_id', $workshops['DISENO']->id)
                ->where('day', 'Martes')
                ->exists()
        );
    }

    public function test_assign_workshop_endpoint_requires_permission(): void
    {
        [$year, $group] = $this->seedYearAndFirstGroup();
        $workshops = $this->seedWorkshops($year, 10);
        $enrollment = $this->makeFirstCandidate($year, $group, 'ZZ', AdmissionWorkshop::Informatics->value, AdmissionWorkshop::IndustrialDesign->value, 9);

        $user = User::factory()->create();
        Permission::findOrCreate('view students', 'web');
        $user->givePermissionTo('view students');
        Sanctum::actingAs($user);

        $this->putJson('/api/students/'.$enrollment->student_id.'/workshop', [
            'workshop_id' => $workshops['INFORMATICA']->id,
        ])->assertForbidden();
    }

    public function test_lote_returns_422_when_capacity_is_null(): void
    {
        [$year] = $this->seedYearAndFirstGroup();
        $this->seedWorkshops($year, 10);
        WorkshopOffering::query()->update(['capacity' => null]);

        $user = User::factory()->create();
        Permission::findOrCreate('edit admission enrollment', 'web');
        $user->givePermissionTo('edit admission enrollment');
        Sanctum::actingAs($user);

        $this->postJson('/api/admissions/enrollments/first-grade-workshop-assignment', [
            'academic_year_id' => $year->id,
            'dry_run' => true,
        ])->assertStatus(422);
    }

    public function test_offerings_can_be_saved_and_read(): void
    {
        [$year] = $this->seedYearAndFirstGroup();
        $workshops = $this->seedWorkshops($year, null);

        $user = User::factory()->create();
        Permission::findOrCreate('edit student workshops', 'web');
        $user->givePermissionTo('edit student workshops');
        Sanctum::actingAs($user);

        $this->putJson('/api/workshop-offerings', [
            'academic_year_id' => $year->id,
            'offerings' => [
                ['workshop_id' => $workshops['INFORMATICA']->id, 'capacity' => 40],
            ],
        ])->assertOk()->assertJsonFragment([
            'workshop_id' => $workshops['INFORMATICA']->id,
            'capacity' => 40,
        ]);

        $this->getJson('/api/workshop-offerings?academic_year_id='.$year->id)
            ->assertOk();
    }

    public function test_promotion_counts_missing_and_warns_over_capacity(): void
    {
        $from = AcademicYear::factory()->create(['is_active' => false]);
        $to = AcademicYear::factory()->create(['is_active' => true]);
        $g1 = GradeLevel::create(['name' => '1°']);
        $g2 = GradeLevel::create(['name' => '2°']);
        $fromGroup = ClassGroup::create(['academic_year_id' => $from->id, 'grade_level_id' => $g1->id, 'name' => 'A']);
        ClassGroup::create(['academic_year_id' => $to->id, 'grade_level_id' => $g2->id, 'name' => 'A']);
        $workshops = $this->seedWorkshops($from, 10);
        $this->seedWorkshops($to, 1);

        $withTaller = $this->makeBareStudent('Con', 'Taller');
        Enrollment::create([
            'student_id' => $withTaller->id,
            'class_group_id' => $fromGroup->id,
            'academic_year_id' => $from->id,
            'status' => EnrollmentStatus::Active,
            'is_approved' => true,
        ]);
        WorkshopEnrollment::create([
            'student_id' => $withTaller->id,
            'workshop_id' => $workshops['INFORMATICA']->id,
            'academic_year_id' => $from->id,
            'source' => WorkshopEnrollmentSource::FirstChoice,
            'status' => WorkshopEnrollmentStatus::Assigned,
        ]);

        $without = $this->makeBareStudent('Sin', 'Taller');
        Enrollment::create([
            'student_id' => $without->id,
            'class_group_id' => $fromGroup->id,
            'academic_year_id' => $from->id,
            'status' => EnrollmentStatus::Active,
            'is_approved' => true,
        ]);

        $extra = $this->makeBareStudent('Otro', 'Taller');
        Enrollment::create([
            'student_id' => $extra->id,
            'class_group_id' => $fromGroup->id,
            'academic_year_id' => $from->id,
            'status' => EnrollmentStatus::Active,
            'is_approved' => true,
        ]);
        WorkshopEnrollment::create([
            'student_id' => $extra->id,
            'workshop_id' => $workshops['INFORMATICA']->id,
            'academic_year_id' => $from->id,
            'source' => WorkshopEnrollmentSource::FirstChoice,
            'status' => WorkshopEnrollmentStatus::Assigned,
        ]);

        $summary = app(EnrollmentPromotionService::class)->promote($from->id, $to->id, false);

        $this->assertSame(2, $summary['workshops_inherited']);
        $this->assertSame(1, $summary['workshops_missing']);
        $this->assertContains($without->id, $summary['workshop_missing_student_ids']);
        $this->assertNotEmpty($summary['workshops_over_capacity']);
    }

    public function test_reenrollment_promote_period_returns_workshop_summary(): void
    {
        $from = AcademicYear::factory()->create(['is_active' => false]);
        $to = AcademicYear::factory()->create(['is_active' => true]);
        $g1 = GradeLevel::create(['name' => '1°']);
        $g2 = GradeLevel::create(['name' => '2°']);
        $fromGroup = ClassGroup::create(['academic_year_id' => $from->id, 'grade_level_id' => $g1->id, 'name' => 'A']);
        ClassGroup::create(['academic_year_id' => $to->id, 'grade_level_id' => $g2->id, 'name' => 'A']);
        $workshops = $this->seedWorkshops($from, 10);
        $this->seedWorkshops($to, 10);

        $student = $this->makeBareStudent('Re', 'Inscrito');
        Enrollment::create([
            'student_id' => $student->id,
            'class_group_id' => $fromGroup->id,
            'academic_year_id' => $from->id,
            'status' => EnrollmentStatus::Active,
            'is_approved' => true,
        ]);
        WorkshopEnrollment::create([
            'student_id' => $student->id,
            'workshop_id' => $workshops['INFORMATICA']->id,
            'academic_year_id' => $from->id,
            'source' => WorkshopEnrollmentSource::FirstChoice,
            'status' => WorkshopEnrollmentStatus::Assigned,
        ]);

        $user = User::factory()->create();
        $period = ReEnrollmentPeriod::create([
            'name' => 'Reinscripcion test',
            'from_academic_year_id' => $from->id,
            'to_academic_year_id' => $to->id,
            'start_at' => now()->subDay(),
            'end_at' => now()->addMonth(),
            'status' => ReEnrollmentPeriodStatus::OPEN,
            'current_step' => ReEnrollmentProcessStep::PROMOTION,
            'keep_current_groups' => true,
            'created_by' => $user->id,
        ]);

        $summary = app(ReEnrollmentService::class)->promotePeriod($period, true);

        $this->assertSame(1, $summary['workshops_inherited']);
        $this->assertSame(0, $summary['workshops_missing']);
    }

    public function test_workshop_attendance_lists_only_assigned_students(): void
    {
        $year = AcademicYear::factory()->create(['is_active' => true]);
        $grade = GradeLevel::create(['name' => '2°']);
        $group = ClassGroup::create(['academic_year_id' => $year->id, 'grade_level_id' => $grade->id, 'name' => 'A']);
        $subject = $this->tecnologiaSubject();
        $workshops = $this->seedWorkshops($year, 10);

        $infoStudent = $this->makeBareStudent('Info', 'Alumno');
        $confStudent = $this->makeBareStudent('Conf', 'Alumno');
        Enrollment::create([
            'student_id' => $infoStudent->id,
            'class_group_id' => $group->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
        ]);
        Enrollment::create([
            'student_id' => $confStudent->id,
            'class_group_id' => $group->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
        ]);
        WorkshopEnrollment::create([
            'student_id' => $infoStudent->id,
            'workshop_id' => $workshops['INFORMATICA']->id,
            'academic_year_id' => $year->id,
            'source' => WorkshopEnrollmentSource::Manual,
            'status' => WorkshopEnrollmentStatus::Assigned,
        ]);
        WorkshopEnrollment::create([
            'student_id' => $confStudent->id,
            'workshop_id' => $workshops['CONFECCION']->id,
            'academic_year_id' => $year->id,
            'source' => WorkshopEnrollmentSource::Manual,
            'status' => WorkshopEnrollmentStatus::Assigned,
        ]);

        $schoolClass = SchoolClass::create([
            'subject_id' => $subject->id,
            'class_group_id' => $group->id,
            'teacher_id' => null,
        ]);
        $schedule = Schedule::create([
            'school_class_id' => $schoolClass->id,
            'workshop_id' => $workshops['INFORMATICA']->id,
            'teacher_id' => null,
            'day' => 'Lunes',
            'start_time' => '10:00:00',
            'end_time' => '12:00:00',
            'schedule_type' => 'workshop',
        ]);

        $user = User::factory()->create();
        $this->grantSchedulePermissions($user, ['view attendance', 'view all schedules']);
        Sanctum::actingAs($user);

        $response = $this->getJson('/api/class/'.$schedule->id.'/date/'.now()->toDateString());
        $response->assertOk();
        $ids = collect($response->json('students'))->pluck('student_id');
        $this->assertTrue($ids->contains($infoStudent->id));
        $this->assertFalse($ids->contains($confStudent->id));
    }

    public function test_record_attendance_upserts_by_schedule_student_and_date(): void
    {
        $year = AcademicYear::factory()->create(['is_active' => true]);
        $grade = GradeLevel::create(['name' => '1°']);
        $group = ClassGroup::create(['academic_year_id' => $year->id, 'grade_level_id' => $grade->id, 'name' => 'A']);
        $subject = Subject::query()->firstOrCreate(['name' => 'Español']);
        $student = $this->makeBareStudent('Ana', 'Lopez');
        Enrollment::create([
            'student_id' => $student->id,
            'class_group_id' => $group->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
        ]);
        $schoolClass = SchoolClass::create([
            'subject_id' => $subject->id,
            'class_group_id' => $group->id,
            'teacher_id' => null,
        ]);
        $schedule = Schedule::create([
            'school_class_id' => $schoolClass->id,
            'day' => 'Lunes',
            'start_time' => '07:00:00',
            'end_time' => '07:45:00',
            'schedule_type' => 'regular',
        ]);

        $user = User::factory()->create();
        $this->grantSchedulePermissions($user, ['view attendance', 'edit attendance', 'view all schedules']);
        Sanctum::actingAs($user);

        $this->postJson('/api/record', [
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'date' => '2026-09-11',
            'status' => 'present',
        ])->assertOk()->assertJsonPath('success', true);

        $this->postJson('/api/record', [
            'student_id' => $student->id,
            'schedule_id' => $schedule->id,
            'date' => '2026-09-11',
            'status' => 'late',
        ])->assertOk();

        $this->assertSame(1, Attendance::query()->count());
        $this->assertSame('late', Attendance::query()->first()->status);
    }

    public function test_record_attendance_batch_saves_the_group(): void
    {
        $year = AcademicYear::factory()->create(['is_active' => true]);
        $grade = GradeLevel::create(['name' => '1°']);
        $group = ClassGroup::create(['academic_year_id' => $year->id, 'grade_level_id' => $grade->id, 'name' => 'B']);
        $subject = Subject::query()->firstOrCreate(['name' => 'Español']);
        $ana = $this->makeBareStudent('Ana', 'Lote');
        $beto = $this->makeBareStudent('Beto', 'Lote');
        foreach ([$ana, $beto] as $student) {
            Enrollment::create([
                'student_id' => $student->id,
                'class_group_id' => $group->id,
                'academic_year_id' => $year->id,
                'status' => EnrollmentStatus::Active,
            ]);
        }
        $schoolClass = SchoolClass::create([
            'subject_id' => $subject->id,
            'class_group_id' => $group->id,
            'teacher_id' => null,
        ]);
        $schedule = Schedule::create([
            'school_class_id' => $schoolClass->id,
            'day' => 'Martes',
            'start_time' => '07:00:00',
            'end_time' => '07:50:00',
            'schedule_type' => 'regular',
        ]);

        $user = User::factory()->create();
        $this->grantSchedulePermissions($user, ['view attendance', 'edit attendance', 'view all schedules']);
        Sanctum::actingAs($user);

        $this->postJson('/api/record-batch', [
            'schedule_id' => $schedule->id,
            'date' => '2026-09-08',
            'records' => [
                ['student_id' => $ana->id, 'status' => 'present'],
                ['student_id' => $beto->id, 'status' => 'absent'],
            ],
        ])->assertOk()->assertJsonPath('saved', 2);

        $this->assertSame('present', Attendance::query()->where('student_id', $ana->id)->value('status'));
        $this->assertSame('absent', Attendance::query()->where('student_id', $beto->id)->value('status'));
    }

    public function test_bulk_gh_assigns_informatics_to_g(): void
    {
        $year = AcademicYear::factory()->create(['is_active' => true]);
        $grade = GradeLevel::create(['name' => '2°']);
        $groupG = ClassGroup::create(['academic_year_id' => $year->id, 'grade_level_id' => $grade->id, 'name' => 'G']);
        $workshops = $this->seedWorkshops($year, 10);
        $student = $this->makeBareStudent('Grupo', 'Ge');
        Enrollment::create([
            'student_id' => $student->id,
            'class_group_id' => $groupG->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
        ]);

        $result = app(AssignBulkGhWorkshopsService::class)->run($year->id, dryRun: false);

        $this->assertSame(1, $result['summary']['assigned']);
        $this->assertSame(
            $workshops['INFORMATICA']->id,
            WorkshopEnrollment::query()->where('student_id', $student->id)->value('workshop_id')
        );
        $this->assertSame(
            WorkshopEnrollmentSource::BulkGh,
            WorkshopEnrollment::query()->where('student_id', $student->id)->first()?->source
        );
    }

    public function test_class_groups_are_unique_per_year_grade_letter(): void
    {
        [$year, $group] = $this->seedYearAndFirstGroup();

        $this->expectException(\Illuminate\Database\QueryException::class);

        ClassGroup::create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $group->grade_level_id,
            'name' => 'A',
        ]);
    }

    /**
     * @return array<string, Workshop>
     */
    private function seedWorkshops(AcademicYear $year, ?int $capacity): array
    {
        $map = [];
        foreach (AdmissionWorkshop::cases() as $case) {
            $workshop = Workshop::query()->updateOrCreate(
                ['code' => $case->code()],
                ['name' => $case->value, 'is_active' => true]
            );
            WorkshopOffering::query()->updateOrCreate(
                ['workshop_id' => $workshop->id, 'academic_year_id' => $year->id],
                ['capacity' => $capacity, 'is_open_for_intake' => true]
            );
            $map[$case->code()] = $workshop;
        }

        return $map;
    }

    /**
     * @return array{0: AcademicYear, 1: ClassGroup}
     */
    private function tecnologiaSubject(): Subject
    {
        return Subject::query()->firstOrCreate(
            ['code' => 'TECNOLOGIA'],
            ['name' => 'Tecnología']
        );
    }

    private function makeBareStudent(string $firstName, string $lastName): Student
    {
        $profile = Profile::create([
            'national_id' => strtoupper(fake()->unique()->bothify('????######H?????##')),
            'first_name' => $firstName,
            'last_name' => $lastName,
            'gender' => 'O',
        ]);

        return Student::create(['profile_id' => $profile->id]);
    }

    private function seedYearAndFirstGroup(): array
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

    /**
     * @return array{0: AcademicYear, 1: Student}
     */
    private function seedStudentInFirst(): array
    {
        [$year, $group] = $this->seedYearAndFirstGroup();
        $enrollment = $this->makeFirstCandidate($year, $group, 'U1', AdmissionWorkshop::Informatics->value, AdmissionWorkshop::IndustrialDesign->value, 8);

        return [$year, $enrollment->student];
    }

    private function makeFirstCandidate(
        AcademicYear $year,
        ClassGroup $group,
        string $lastName,
        string $first,
        string $second,
        float $average,
    ): Enrollment {
        $profile = Profile::create([
            'national_id' => strtoupper(fake()->unique()->bothify('????######H?????##')),
            'first_name' => 'Alumno',
            'last_name' => $lastName,
            'gender' => 'O',
        ]);
        $student = Student::create(['profile_id' => $profile->id]);
        $enrollment = Enrollment::create([
            'student_id' => $student->id,
            'class_group_id' => $group->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
            'is_new_admission' => true,
            'placement_status' => 'provisional',
        ]);
        PreEnrollment::factory()->create([
            'converted_student_id' => $student->id,
            'workshop_first_choice' => $first,
            'workshop_second_choice' => $second,
            'current_average' => $average,
            'admission_exam_score' => $average,
        ]);

        return $enrollment;
    }

    /**
     * @param  list<string>  $names
     */
    private function grantSchedulePermissions(User $user, array $names): void
    {
        foreach ($names as $name) {
            Permission::findOrCreate($name, 'web');
        }
        $user->givePermissionTo($names);
    }

    public function test_excel_import_assigns_regular_teacher_from_academic_sheet(): void
    {
        $year = AcademicYear::factory()->create(['is_active' => true]);
        $grade = GradeLevel::create(['name' => '2°']);
        ClassGroup::create(['academic_year_id' => $year->id, 'grade_level_id' => $grade->id, 'name' => 'A']);
        Subject::query()->firstOrCreate(['name' => 'Español']);
        $this->tecnologiaSubject();
        $this->seedWorkshops($year, 10);

        $teacherProfile = Profile::create([
            'national_id' => strtoupper(fake()->unique()->bothify('????######H?????##')),
            'first_name' => 'Esmeralda',
            'last_name' => 'Orozco',
            'gender' => 'F',
        ]);
        $teacher = Teacher::create(['profile_id' => $teacherProfile->id, 'status' => 'active']);

        $summary = app(ImportSchedulesFromExcelService::class)->import(
            academicYearId: $year->id,
            gruposPath: $this->writeGruposFixture(),
            docentesPath: $this->writeAcademicDocentesFixture(),
            dryRun: false,
        );

        $this->assertGreaterThanOrEqual(1, $summary['teachers_assigned']);
        $this->assertTrue(
            Schedule::query()
                ->where('schedule_type', 'regular')
                ->where('day', 'Lunes')
                ->where('teacher_id', $teacher->id)
                ->exists()
        );
    }

    private function writeGruposFixture(): string
    {
        $ss = new Spreadsheet;
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('2A');
        $sheet->fromArray([
            ['Hora', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'],
            ['07:00-07:50', 'Español', 'Español', 'Español', 'Español', 'Español'],
            ['10:00-12:00', 'Tecnología', 'Tecnología', 'Tecnología', 'Tecnología', 'Tecnología'],
        ]);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'grupos-fixture.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }

    private function writeDocentesFixture(): string
    {
        $ss = new Spreadsheet;
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Raymundo Cruz');
        $sheet->fromArray([
            ['TALLER DE: Diseño Industrial', '', '', '', '', ''],
            ['Hora', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'],
            ['10:00-12:00', '2 A', '2 A', '2 A', '2 A', '2 A'],
        ]);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'docentes-fixture.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }

    private function writeAcademicDocentesFixture(): string
    {
        $ss = new Spreadsheet;
        $sheet = $ss->getActiveSheet();
        $sheet->setTitle('Esmeralda');
        $sheet->fromArray([
            ['Hora', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes'],
            ['07:00-07:50', '2 A', '2 A', '2 A', '2 A', '2 A'],
        ]);
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.'docentes-academico-fixture.xlsx';
        (new Xlsx($ss))->save($path);

        return $path;
    }
}
