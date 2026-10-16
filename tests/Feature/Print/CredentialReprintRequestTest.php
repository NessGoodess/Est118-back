<?php

namespace Tests\Feature\Print;

use App\Enums\CredentialReprintStatus;
use App\Enums\EnrollmentStatus;
use App\Models\AcademicYear;
use App\Models\CardDesign;
use App\Models\ClassGroup;
use App\Models\CredentialPrint;
use App\Models\CredentialReprintRequest;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\PrintJob;
use App\Models\Profile;
use App\Models\Student;
use App\Models\User;
use App\Services\Print\CredentialPrintService;
use App\Services\Print\PrintJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CredentialReprintRequestTest extends TestCase
{
    use RefreshDatabase;

    private const ALL = [
        'view students',
        'edit students',
        'request credential reprints',
        'approve credential reprints',
        'register credential payments',
    ];

    private User $user;

    private ClassGroup $group;

    private Student $student;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = $this->authUser(self::ALL);

        $year = AcademicYear::factory()->create(['is_active' => true, 'year_start' => '2026', 'year_end' => '2027']);
        $grade = GradeLevel::query()->firstOrCreate(['name' => '2°']);
        $this->group = ClassGroup::query()->create([
            'academic_year_id' => $year->id,
            'grade_level_id' => $grade->id,
            'name' => 'B',
        ]);
        CardDesign::query()->create([
            'user_id' => $this->user->id,
            'name' => 'Frente 2026',
            'audience' => 'students',
            'faces_mode' => 'single',
            'orientation' => 'landscape',
            'is_active' => true,
            'is_default' => true,
        ]);

        $profile = Profile::query()->create([
            'national_id' => 'ANA140101MOCRRNA1',
            'first_name' => 'Ana',
            'last_name' => 'Lopez',
            'gender' => 'F',
            'birth_date' => '2014-01-01',
        ]);
        $this->student = Student::query()->create(['profile_id' => $profile->id]);
        Enrollment::query()->create([
            'student_id' => $this->student->id,
            'class_group_id' => $this->group->id,
            'academic_year_id' => $year->id,
            'status' => EnrollmentStatus::Active,
        ]);
    }

    public function test_chargeable_request_goes_through_approval_payment_and_printing(): void
    {
        $uuid = $this->postJson('/api/credential-reprint-requests', [
            'student_id' => $this->student->id,
            'reason' => 'lost',
            'requester_relation' => 'guardian',
            'requester_name' => 'María López',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.is_chargeable', true)
            ->assertJsonPath('data.grade_label', '2°')
            ->assertJsonPath('data.group_label', 'B')
            ->assertJsonPath('data.student_name', 'Ana Lopez')
            ->json('data.uuid');

        $this->postJson('/api/credential-reprint-requests', [
            'student_id' => $this->student->id,
            'reason' => 'wants_new',
        ])->assertUnprocessable()->assertJsonValidationErrors('student_id');

        $this->postJson("/api/credential-reprint-requests/{$uuid}/approve")
            ->assertOk()->assertJsonPath('data.status', 'awaiting_payment');
        $this->postJson("/api/credential-reprint-requests/{$uuid}/payment", ['folio' => 'F-12', 'amount' => 50])
            ->assertOk()->assertJsonPath('data.status', 'ready_to_print')
            ->assertJsonPath('data.payment_folio', 'F-12');

        app(CredentialPrintService::class)->createBatch([$this->student->id], $this->user);
        $request = CredentialReprintRequest::query()->sole();
        $card = CredentialPrint::query()->sole();
        $this->assertSame(CredentialReprintStatus::Queued, $request->status);
        $this->assertSame($card->id, $request->credential_print_id);

        app(PrintJobService::class)->markCompleted(PrintJob::query()->sole());
        $request->refresh();
        $this->assertSame(CredentialReprintStatus::Printed, $request->status);
        $this->assertNotNull($request->printed_at);
        $this->assertNull($request->open_student_id);

        $this->getJson('/api/credential-reprint-requests?bucket=closed')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('meta.counts.closed', 1);
    }

    public function test_print_error_is_auto_approved_and_discard_returns_it_to_the_print_list(): void
    {
        $this->postJson('/api/credential-reprint-requests', [
            'student_id' => $this->student->id,
            'reason' => 'print_error',
        ])->assertCreated()
            ->assertJsonPath('data.status', 'ready_to_print')
            ->assertJsonPath('data.is_chargeable', false);

        $batch = app(CredentialPrintService::class)->createBatch([$this->student->id], $this->user);
        app(CredentialPrintService::class)->discard($this->user, [$batch['cards']->first()->id], null, 'Atasco');

        $request = CredentialReprintRequest::query()->sole();
        $this->assertSame(CredentialReprintStatus::ReadyToPrint, $request->status);
        $this->assertNull($request->credential_print_id);
    }

    public function test_other_reason_requires_notes_and_reject_closes_the_request(): void
    {
        $this->postJson('/api/credential-reprint-requests', [
            'student_id' => $this->student->id,
            'reason' => 'other',
        ])->assertUnprocessable()->assertJsonValidationErrors('notes');

        $uuid = $this->postJson('/api/credential-reprint-requests', [
            'student_id' => $this->student->id,
            'reason' => 'other',
            'notes' => 'Cambio de foto',
        ])->assertCreated()->json('data.uuid');

        $this->postJson("/api/credential-reprint-requests/{$uuid}/reject", ['reason' => 'No procede'])
            ->assertOk()->assertJsonPath('data.status', 'rejected');

        $this->postJson("/api/credential-reprint-requests/{$uuid}/payment")
            ->assertUnprocessable();
        $this->assertNull(CredentialReprintRequest::query()->sole()->open_student_id);
    }

    public function test_reception_cannot_approve_or_register_payment(): void
    {
        $this->authUser(['request credential reprints']);
        $uuid = $this->postJson('/api/credential-reprint-requests', [
            'student_id' => $this->student->id,
            'reason' => 'physical_damage',
        ])->assertCreated()->json('data.uuid');

        $this->postJson("/api/credential-reprint-requests/{$uuid}/approve")->assertForbidden();
        $this->postJson("/api/credential-reprint-requests/{$uuid}/payment")->assertForbidden();
        $this->getJson('/api/credential-reprint-requests/summary')
            ->assertOk()->assertJsonPath('data.pending', 1);
        $this->getJson('/api/credential-reprint-requests/students?search=ana lop')
            ->assertOk()
            ->assertJsonPath('data.0.id', $this->student->id)
            ->assertJsonPath('data.0.group_label', 'B')
            ->assertJsonPath('data.0.open_request_status', 'pending');
    }

    /** @param  array<int, string>  $permissions */
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
