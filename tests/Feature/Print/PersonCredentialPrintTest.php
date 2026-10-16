<?php

namespace Tests\Feature\Print;

use App\Enums\CredentialHolderKind;
use App\Enums\CredentialSideStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\PrintJobStatus;
use App\Jobs\RenderStudentCardJob;
use App\Models\AcademicYear;
use App\Models\CardDesign;
use App\Models\ClassGroup;
use App\Models\CredentialPrint;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\PrintJob;
use App\Models\Profile;
use App\Models\Staff;
use App\Models\Student;
use App\Models\StudentCredentialTracking;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Print\CredentialPrintService;
use App\Services\Print\PrintJobService;
use App\Services\Print\StudentCardRenderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class PersonCredentialPrintTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = $this->authUser(['print credentials']);
    }

    public function test_teacher_batch_creates_card_and_job_without_student(): void
    {
        $design = $this->design('teachers', 'single');
        $teacher = $this->teacher('ARACELI', 'VILLANUEVA', 'MARTINEZ', 'A-001');

        $response = $this->postJson('/api/credential-prints/people', [
            'kind' => 'teachers',
            'ids' => [$teacher->id],
        ])->assertStatus(202);

        $response->assertJsonPath('data.cards.0.kind', 'teachers')
            ->assertJsonPath('data.cards.0.student_id', null)
            ->assertJsonPath('data.cards.0.profile_id', $teacher->profile_id)
            ->assertJsonPath('data.cards.0.student_name', 'ARACELI VILLANUEVA MARTINEZ')
            ->assertJsonPath('data.cards.0.design_key', $design->uuid);

        $card = CredentialPrint::query()->sole();
        $this->assertNull($card->student_id);
        $this->assertSame(CredentialHolderKind::Teachers, $card->kind);
        $this->assertSame(CredentialSideStatus::NotApplicable, $card->back_status);

        $job = PrintJob::query()->sole();
        $this->assertNull($job->student_id);
        $this->assertSame($teacher->profile_id, $job->profile_id);
        $this->assertSame(CredentialHolderKind::Teachers, $job->kind);
        $this->assertSame('ARACELI VILLANUEVA MARTINEZ', $job->payload_json['full_name']);
        $this->assertSame('Docente', $job->payload_json['role']);
        $this->assertSame('A-001', $job->payload_json['credential_id']);
        $this->assertSame($teacher->id, $job->payload_json['holder_id']);
        Queue::assertPushed(RenderStudentCardJob::class);

        app(PrintJobService::class)->markCompleted($job);
        $card->refresh();
        $this->assertTrue($card->isComplete());
        $this->assertSame(0, StudentCredentialTracking::query()->count());
    }

    public function test_teacher_job_renders_without_a_student(): void
    {
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('GD is required to render cards.');
        }
        Storage::fake('private');
        Storage::fake('public');

        $this->design('teachers', 'single');
        $teacher = $this->teacher('ANA', 'LOPEZ', null, 'A-010');
        app(CredentialPrintService::class)->createPersonBatch(CredentialHolderKind::Teachers, [$teacher->id], $this->user);

        $job = app(StudentCardRenderService::class)->render(PrintJob::query()->sole());

        $this->assertSame(PrintJobStatus::Ready, $job->status);
        $this->assertNotNull($job->front_path);
    }

    public function test_staff_badge_uses_position_and_staff_design(): void
    {
        $this->design('teachers', 'single');
        $staffDesign = $this->design('staff', 'double');
        $staff = $this->staff('ROSA', 'PEREZ', 'Secretaria', 'Administración');

        $this->postJson('/api/credential-prints/people', [
            'kind' => 'staff',
            'ids' => [$staff->id],
        ])->assertStatus(202)
            ->assertJsonPath('data.cards.0.design_key', $staffDesign->uuid)
            ->assertJsonPath('data.cards.0.back_status', 'pending');

        $job = PrintJob::query()->sole();
        $this->assertSame('Secretaria', $job->payload_json['role']);
        $this->assertSame('Administración', $job->payload_json['department']);
    }

    public function test_open_badge_is_skipped_on_resend(): void
    {
        $this->design('teachers', 'single');
        $teacher = $this->teacher('ANA', 'LOPEZ', null, 'A-002');

        $this->postJson('/api/credential-prints/people', ['kind' => 'teachers', 'ids' => [$teacher->id]])
            ->assertStatus(202);

        $this->postJson('/api/credential-prints/people', ['kind' => 'teachers', 'ids' => [$teacher->id]])
            ->assertOk()
            ->assertJsonPath('data.batch_uuid', null)
            ->assertJsonPath('data.skipped.0.id', $teacher->id)
            ->assertJsonPath('data.skipped.0.reason', 'in_queue');

        $this->assertSame(1, CredentialPrint::query()->count());
    }

    public function test_missing_audience_design_is_rejected(): void
    {
        $this->design('students', 'single');
        $teacher = $this->teacher('ANA', 'LOPEZ', null, 'A-003');

        $this->postJson('/api/credential-prints/people', ['kind' => 'teachers', 'ids' => [$teacher->id]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('template_key');
    }

    public function test_students_kind_is_not_accepted(): void
    {
        $this->postJson('/api/credential-prints/people', ['kind' => 'students', 'ids' => [1]])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('kind');
    }

    public function test_people_index_lists_active_holders_with_latest_card(): void
    {
        $this->design('teachers', 'single');
        $active = $this->teacher('ANA', 'LOPEZ', null, 'A-004');
        $inactive = $this->teacher('LUIS', 'MORA', null, 'A-005');
        $inactive->update(['status' => 'inactive']);

        app(CredentialPrintService::class)->createPersonBatch(CredentialHolderKind::Teachers, [$active->id], $this->user);

        $this->getJson('/api/credential-prints/people?kind=teachers')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id)
            ->assertJsonPath('data.0.name', 'ANA LOPEZ')
            ->assertJsonPath('data.0.latest_card.kind', 'teachers');
    }

    public function test_print_credentials_permission_is_required(): void
    {
        $this->authUser(['view students', 'edit students']);

        $this->getJson('/api/credential-prints/people?kind=teachers')->assertForbidden();
        $this->postJson('/api/credential-prints/people', ['kind' => 'teachers', 'ids' => [1]])->assertForbidden();
    }

    public function test_student_cards_record_profile_and_kind(): void
    {
        $year = AcademicYear::factory()->create(['is_active' => true, 'year_start' => '2026', 'year_end' => '2027']);
        $grade = GradeLevel::query()->firstOrCreate(['name' => '1°']);
        $group = ClassGroup::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'name' => 'A',
        ]);
        $design = $this->design('students', 'single');
        $profile = Profile::query()->create([
            'national_id' => 'ANA140101MOCRRNA1',
            'first_name' => 'Ana',
            'last_name' => 'Lopez',
        ]);
        $student = Student::query()->create(['profile_id' => $profile->id]);
        Enrollment::query()->create([
            'student_id' => $student->id,
            'class_group_id' => $group->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
        ]);

        app(CredentialPrintService::class)->createBatch([$student->id], $this->user, $design->uuid);

        $card = CredentialPrint::query()->sole();
        $this->assertSame($profile->id, $card->profile_id);
        $this->assertSame(CredentialHolderKind::Students, $card->kind);
        $job = PrintJob::query()->sole();
        $this->assertSame($profile->id, $job->profile_id);
        $this->assertSame(CredentialHolderKind::Students, $job->kind);
    }

    private function design(string $audience, string $faces): CardDesign
    {
        return CardDesign::query()->create([
            'user_id' => $this->user->id,
            'name' => "Gafete {$audience}",
            'audience' => $audience,
            'faces_mode' => $faces,
            'orientation' => 'portrait',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    private function teacher(string $first, string $last, ?string $second, string $employee): Teacher
    {
        $profile = Profile::query()->create([
            'first_name' => $first,
            'last_name' => $last,
            'second_last_name' => $second,
        ]);

        return Teacher::query()->create([
            'profile_id' => $profile->id,
            'employee' => $employee,
            'status' => 'active',
        ]);
    }

    private function staff(string $first, string $last, string $position, string $department): Staff
    {
        $profile = Profile::query()->create([
            'first_name' => $first,
            'last_name' => $last,
        ]);

        return Staff::query()->create([
            'profile_id' => $profile->id,
            'position' => $position,
            'department' => $department,
            'status' => 'active',
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
