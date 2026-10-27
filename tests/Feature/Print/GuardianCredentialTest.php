<?php

namespace Tests\Feature\Print;

use App\Enums\CredentialHolderKind;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\CardDesign;
use App\Models\ClassGroup;
use App\Models\CredentialPrint;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\Guardian;
use App\Models\PrintJob;
use App\Models\Profile;
use App\Models\Student;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Print\StudentCardRenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class GuardianCredentialTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AcademicYear $year;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = $this->authUser(['print credentials', 'view students']);
        $this->year = AcademicYear::factory()->create(['is_active' => true, 'year_start' => '2026', 'year_end' => '2027']);
    }

    public function test_only_guardians_with_an_active_enrollment_are_listed(): void
    {
        $mother = $this->guardian('PATRICIA', 'SANCHEZ', 'MORALES');
        $father = $this->guardian('MIGUEL', 'SANCHEZ', 'MORALES');
        $student = $this->student('SANTIAGO', 'SANCHEZ', '2°', 'F');
        $student->guardians()->attach($mother->id, ['relationship' => 'Madre']);
        $student->guardians()->attach($father->id, ['relationship' => 'Padre']);

        $orphan = $this->guardian('LUIS', 'MORA', null);
        $this->student('ANA', 'MORA', '1°', 'A', active: false)->guardians()->attach($orphan->id);

        $response = $this->getJson('/api/credential-prints/people?kind=guardians')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->assertNotContains($orphan->id, collect($response->json('data'))->pluck('id'));

        $response->assertJsonPath('data.0.kind', 'guardians')
            ->assertJsonPath('data.0.dependents.0.grade_group', '2° F')
            ->assertJsonPath('data.0.dependents.0.first_name', 'SANTIAGO');

        $row = collect($this->getJson('/api/credential-prints/people?kind=guardians')->json('data'))
            ->firstWhere('id', $mother->id);
        $this->assertSame('Madre', $row['kinship']);
        $this->assertSame('T'.str_pad((string) $mother->id, 4, '0', STR_PAD_LEFT), $row['folio']);
        $this->assertSame(
            ['name' => 'MIGUEL SANCHEZ MORALES', 'phone' => '', 'kinship' => 'Padre'],
            $row['second_guardian']
        );
    }

    public function test_guardian_payload_lists_every_active_minor(): void
    {
        $mother = $this->guardian('CARLOS', 'MARTINEZ', 'LOPEZ');
        $this->student('CAMILA', 'MARTINEZ', '1°', 'B')->guardians()->attach($mother->id, ['relationship' => 'Padre']);
        $this->student('MATEO', 'MARTINEZ', '3°', 'E')->guardians()->attach($mother->id, ['relationship' => 'Padre']);

        $payload = app(StudentCardRenderService::class)->payloadFromGuardian($mother->fresh());

        $this->assertSame('CARLOS MARTINEZ LOPEZ', $payload['full_name']);
        $this->assertSame('Padre', $payload['kinship']);
        $this->assertSame("1° B · MARTINEZ, CAMILA\n3° E · MARTINEZ, MATEO", $payload['dependents']);
        $this->assertSame('1° B · MARTINEZ, CAMILA', $payload['dependent']);
        $this->assertSame('No registrado', $payload['second_guardian']);
        $this->assertSame('guardians', $payload['holder_kind']);
    }

    public function test_second_guardian_card_field_is_name_phone_kinship_lines(): void
    {
        $mother = $this->guardian('PATRICIA', 'SANCHEZ', 'MORALES');
        $father = $this->guardian('MIGUEL', 'SANCHEZ', 'MORALES');
        $father->profile->update(['phone_number' => '722 555 1212']);
        $student = $this->student('SANTIAGO', 'SANCHEZ', '2°', 'F');
        $student->guardians()->attach($mother->id, ['relationship' => 'Madre']);
        $student->guardians()->attach($father->id, ['relationship' => 'Padre']);

        $payload = app(StudentCardRenderService::class)->payloadFromGuardian($mother->fresh());

        $this->assertSame("MIGUEL SANCHEZ MORALES\n722 555 1212\nPadre", $payload['second_guardian']);
    }

    public function test_guardian_batch_goes_to_the_zebra_with_guardian_design(): void
    {
        $design = $this->design('guardians', 'double');
        $guardian = $this->guardian('ELENA', 'HERNANDEZ', null);
        $this->student('DIEGO', 'HERNANDEZ', '1°', 'A')->guardians()->attach($guardian->id, ['relationship' => 'Madre']);

        $this->postJson('/api/credential-prints/people', ['kind' => 'guardians', 'ids' => [$guardian->id]])
            ->assertStatus(202)
            ->assertJsonPath('data.cards.0.kind', 'guardians')
            ->assertJsonPath('data.cards.0.design_key', $design->uuid);

        $job = PrintJob::query()->sole();
        $this->assertSame(CredentialHolderKind::Guardians, $job->kind);
        $this->assertSame('1° A · HERNANDEZ, DIEGO', $job->payload_json['dependent']);
    }

    public function test_guardians_index_filters_by_grade_and_group(): void
    {
        $mother = $this->guardian('PATRICIA', 'SANCHEZ', 'MORALES');
        $onlyFirst = $this->guardian('SOLO', 'PRIMERO', null);
        $camila = $this->student('CAMILA', 'SANCHEZ', '1°', 'B');
        $mateo = $this->student('MATEO', 'SANCHEZ', '3°', 'E');
        $diego = $this->student('DIEGO', 'PRIMERO', '1°', 'B');
        $camila->guardians()->attach($mother->id, ['relationship' => 'Madre']);
        $mateo->guardians()->attach($mother->id, ['relationship' => 'Madre']);
        $diego->guardians()->attach($onlyFirst->id, ['relationship' => 'Madre']);

        $grade1 = GradeLevel::query()->where('name', '1°')->value('id');
        $grade3 = GradeLevel::query()->where('name', '3°')->value('id');
        $group1B = ClassGroup::query()
            ->where('grade_level_id', $grade1)
            ->where('name', 'B')
            ->value('id');

        $this->getJson('/api/credential-prints/people?kind=guardians&grade_id='.$grade1)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->getJson('/api/credential-prints/people?kind=guardians&grade_id='.$grade3)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $mother->id);

        $this->getJson('/api/credential-prints/people?kind=guardians&group_id='.$group1B)
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // Multi-grade tutor still lists every dependent on the card row.
        $row = collect($this->getJson('/api/credential-prints/people?kind=guardians&grade_id='.$grade3)->json('data'))
            ->firstWhere('id', $mother->id);
        $this->assertCount(2, $row['dependents']);
    }

    public function test_guardian_batch_uses_grade_design_or_template_key(): void
    {
        $grade1 = GradeLevel::query()->firstOrCreate(['name' => '1°']);
        $grade3 = GradeLevel::query()->firstOrCreate(['name' => '3°']);
        $fallback = $this->design('guardians', 'single');
        $design1 = $this->design('guardians', 'double', $grade1->id, 'Gafete tutores 1');
        $design3 = $this->design('guardians', 'double', $grade3->id, 'Gafete tutores 3');

        $guardian = $this->guardian('ELENA', 'HERNANDEZ', null);
        $this->student('DIEGO', 'HERNANDEZ', '1°', 'A')->guardians()->attach($guardian->id);

        $this->postJson('/api/credential-prints/people', [
            'kind' => 'guardians',
            'ids' => [$guardian->id],
            'grade_id' => $grade1->id,
        ])->assertStatus(202)
            ->assertJsonPath('data.cards.0.design_key', $design1->uuid);

        CredentialPrint::query()->delete();
        PrintJob::query()->delete();

        $this->postJson('/api/credential-prints/people', [
            'kind' => 'guardians',
            'ids' => [$guardian->id],
            'template_key' => $design3->uuid,
            'grade_id' => $grade1->id,
        ])->assertStatus(202)
            ->assertJsonPath('data.cards.0.design_key', $design3->uuid);

        $this->assertNotSame($fallback->uuid, $design1->uuid);
    }

    public function test_guardian_preview_accepts_grade_and_template(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is required to render cards.');
        }
        Storage::fake('private');
        Storage::fake('public');

        $grade1 = GradeLevel::query()->firstOrCreate(['name' => '1°']);
        $design = $this->design('guardians', 'single', $grade1->id);
        $guardian = $this->guardian('ELENA', 'HERNANDEZ', null);
        $this->student('DIEGO', 'HERNANDEZ', '1°', 'A')->guardians()->attach($guardian->id);

        $this->get('/api/print-jobs/preview?'.http_build_query([
            'kind' => 'guardians',
            'id' => $guardian->id,
            'grade_id' => $grade1->id,
            'template_key' => $design->uuid,
        ]))->assertOk()
            ->assertHeader('Content-Type', 'image/png');
    }

    public function test_sheet_job_renders_async_and_records_nothing(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is required to render cards.');
        }
        Storage::fake('private');
        Storage::fake('public');
        \Illuminate\Support\Facades\Event::fake([\App\Events\CredentialSheetCardUpdated::class]);

        $this->design('guardians', 'double');
        $guardian = $this->guardian('ELENA', 'HERNANDEZ', null);
        $this->student('DIEGO', 'HERNANDEZ', '1°', 'A')->guardians()->attach($guardian->id);

        $started = $this->postJson('/api/credential-sheets/jobs', [
            'kind' => 'guardians',
            'ids' => [$guardian->id],
            'omit_fields' => ['second_guardian'],
        ])->assertAccepted()
            ->assertJsonPath('data.summary.total', 1)
            ->assertJsonPath('data.summary.rendering', 1)
            ->json('data.uuid');

        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\RenderCredentialSheetCardJob::class);

        app(\App\Services\Print\CredentialSheetService::class)->renderCard($started, (int) $guardian->id);

        $status = $this->getJson('/api/credential-sheets/jobs/'.$started)
            ->assertOk()
            ->assertJsonPath('data.status', 'ready')
            ->assertJsonPath('data.summary.ready', 1)
            ->assertJsonPath('data.cards.0.id', $guardian->id)
            ->assertJsonPath('data.cards.0.orientation', 'portrait')
            ->json('data');

        $this->assertStringContainsString('/credential-sheets/jobs/', $status['cards'][0]['front']);
        $this->assertStringContainsString('/credential-sheets/jobs/', $status['cards'][0]['back']);
        $this->assertSame(0, CredentialPrint::query()->count());
        $this->assertSame(0, PrintJob::query()->count());

        \Illuminate\Support\Facades\Event::assertDispatched(\App\Events\CredentialSheetCardUpdated::class);

        $frontUrl = parse_url($status['cards'][0]['front'], PHP_URL_PATH);
        $frontQuery = parse_url($status['cards'][0]['front'], PHP_URL_QUERY);
        $this->get($frontUrl.'?'.$frontQuery)->assertOk();
    }

    public function test_sheet_job_cancel_stops_further_renders(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is required to render cards.');
        }
        Storage::fake('private');
        Storage::fake('public');
        \Illuminate\Support\Facades\Event::fake([\App\Events\CredentialSheetCardUpdated::class]);

        $this->design('guardians', 'double');
        $guardian = $this->guardian('ELENA', 'HERNANDEZ', null);
        $this->student('DIEGO', 'HERNANDEZ', '1°', 'A')->guardians()->attach($guardian->id);

        $uuid = $this->postJson('/api/credential-sheets/jobs', [
            'kind' => 'guardians',
            'ids' => [$guardian->id],
        ])->assertAccepted()->json('data.uuid');

        $this->postJson('/api/credential-sheets/jobs/'.$uuid.'/cancel')
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        $this->getJson('/api/credential-sheets/jobs/'.$uuid)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled');

        app(\App\Services\Print\CredentialSheetService::class)->renderCard($uuid, (int) $guardian->id);

        $this->getJson('/api/credential-sheets/jobs/'.$uuid)
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.summary.ready', 0);

        \Illuminate\Support\Facades\Event::assertNotDispatched(\App\Events\CredentialSheetCardUpdated::class);
    }

    public function test_sheet_job_for_students_needs_view_students(): void
    {
        $this->authUser(['print credentials']);

        $this->postJson('/api/credential-sheets/jobs', ['kind' => 'students', 'ids' => [1]])
            ->assertForbidden();
    }

    public function test_teacher_position_and_department_reach_the_card(): void
    {
        $profile = Profile::query()->create(['first_name' => 'ANA', 'last_name' => 'LOPEZ']);
        $teacher = Teacher::query()->create([
            'profile_id' => $profile->id,
            'status' => 'active',
            'position' => 'Coordinadora',
            'department' => 'Académico',
        ]);

        $payload = app(StudentCardRenderService::class)->payloadFromPerson(CredentialHolderKind::Teachers, $teacher->load('profile'));

        $this->assertSame('Coordinadora', $payload['role']);
        $this->assertSame('Académico', $payload['department']);
    }

    private function guardian(string $first, string $last, ?string $second): Guardian
    {
        $profile = Profile::query()->create([
            'first_name' => $first,
            'last_name' => $last,
            'second_last_name' => $second,
        ]);

        return Guardian::query()->create(['profile_id' => $profile->id]);
    }

    private function student(string $first, string $last, string $grade, string $group, bool $active = true): Student
    {
        $gradeLevel = GradeLevel::query()->firstOrCreate(['name' => $grade]);
        $classGroup = ClassGroup::query()->firstOrCreate([
            'academic_year_id' => $this->year->id,
            'grade_level_id' => $gradeLevel->id,
            'name' => $group,
        ]);
        $profile = Profile::query()->create(['first_name' => $first, 'last_name' => $last]);
        $student = Student::query()->create(['profile_id' => $profile->id]);
        Enrollment::query()->create([
            'student_id' => $student->id,
            'class_group_id' => $classGroup->id,
            'academic_year_id' => $this->year->id,
            'status' => $active ? EnrollmentStatus::Active : EnrollmentStatus::Dropped,
        ]);

        return $student;
    }

    private function design(
        string $audience,
        string $faces,
        ?int $gradeLevelId = null,
        ?string $name = null
    ): CardDesign {
        return CardDesign::query()->create([
            'user_id' => $this->user->id,
            'name' => $name ?? "Gafete {$audience}",
            'audience' => $audience,
            'faces_mode' => $faces,
            'orientation' => 'portrait',
            'grade_level_id' => $gradeLevelId,
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function authUser(array $permissions): User
    {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user = User::factory()->create(['email_verified_at' => now()]);
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $user;
    }
}
