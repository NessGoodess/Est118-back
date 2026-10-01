<?php

namespace Tests\Feature\Print;

use App\Enums\EnrollmentStatus;
use App\Enums\ServiceAbility;
use App\Events\NfcAssignmentUpdated;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use App\Models\NfcAssignments;
use App\Models\Profile;
use App\Models\Student;
use App\Models\StudentCredentialTracking;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class NfcAssignmentFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AcademicYear $year;

    private ClassGroup $group;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->authUser(['view students', 'edit students']);
        [$this->year, $this->group] = $this->period();
    }

    public function test_enqueue_generates_folio_and_verify_sets_nfc_ready_only_when_read_back_matches(): void
    {
        $ana = $this->makeStudent('LOPE140101MOCRRNA1', 'Ana', 'Lopez');
        $this->enroll($ana);

        $created = $this->postJson('/api/nfc-assignments', [
            'student_id' => $ana->id,
            'action' => 'assign',
        ])->assertCreated();

        $ana->refresh();
        $this->assertSame('LOPE140101MOCRRNA1', $ana->credential_id);
        $uuid = $created->json('data.job_id');
        $this->assertSame($ana->credential_id, $created->json('data.credential_id'));
        $this->assertSame('pending', $created->json('data.status'));

        $blocked = $this->postJson('/api/nfc-assignments', [
            'student_id' => $ana->id,
            'action' => 'verify',
        ])->assertStatus(409);
        $this->assertSame($uuid, $blocked->json('data.job_id'));

        Sanctum::actingAs($this->agentUser(), [ServiceAbility::PRINT_AGENT->value]);

        $claimed = $this->getJson('/api/agent/nfc-jobs/next?agent_id=pc-recepcion')
            ->assertOk();
        $this->assertSame($uuid, $claimed->json('data.job_id'));
        $this->assertSame('waiting_card', $claimed->json('data.status'));
        $this->assertSame('assign', $claimed->json('data.action'));

        $this->postJson("/api/agent/nfc-jobs/{$uuid}/progress", [
            'agent_id' => 'pc-recepcion',
            'status' => 'card_detected',
            'nfc_uid' => '04AABBCC',
            'message' => 'Detectada — no la retires',
        ])->assertOk()->assertJsonPath('data.status', 'card_detected');

        $this->postJson("/api/agent/nfc-jobs/{$uuid}/fail", [
            'agent_id' => 'pc-recepcion',
            'failure_code' => 'removed_early',
            'message' => 'La credencial se detectó pero se retiró antes de terminar la escritura.',
            'nfc_uid' => '04AABBCC',
        ])->assertOk()->assertJsonPath('data.status', 'error');

        $this->assertFalse(
            (bool) StudentCredentialTracking::query()->where('student_id', $ana->id)->value('nfc_ready')
        );

        Sanctum::actingAs($this->user);

        $retry = $this->postJson('/api/nfc-assignments', [
            'student_id' => $ana->id,
            'action' => 'assign',
        ])->assertCreated();
        $retryId = $retry->json('data.job_id');

        Sanctum::actingAs($this->agentUser(), [ServiceAbility::PRINT_AGENT->value]);
        $this->getJson('/api/agent/nfc-jobs/next?agent_id=pc-recepcion')->assertOk();

        $mismatch = $this->postJson("/api/agent/nfc-jobs/{$retryId}/complete", [
            'agent_id' => 'pc-recepcion',
            'nfc_uid' => '04AABBCC',
            'read_back' => 'OTRO-FOLIO',
        ])->assertOk();
        $this->assertSame('error', $mismatch->json('data.status'));
        $this->assertSame('verify_mismatch', $mismatch->json('data.failure_code'));
        $this->assertFalse(
            (bool) StudentCredentialTracking::query()->where('student_id', $ana->id)->value('nfc_ready')
        );

        Sanctum::actingAs($this->user);
        $verify = $this->postJson('/api/nfc-assignments', [
            'student_id' => $ana->id,
            'action' => 'verify',
        ])->assertCreated();
        $verifyId = $verify->json('data.job_id');

        Sanctum::actingAs($this->agentUser(), [ServiceAbility::PRINT_AGENT->value]);
        $this->getJson('/api/agent/nfc-jobs/next?agent_id=pc-recepcion')->assertOk();
        $this->postJson("/api/agent/nfc-jobs/{$verifyId}/complete", [
            'agent_id' => 'pc-recepcion',
            'nfc_uid' => '04AABBCC',
            'read_back' => $ana->credential_id,
        ])->assertOk()->assertJsonPath('data.status', 'completed');

        $this->assertTrue(
            (bool) StudentCredentialTracking::query()
                ->where('student_id', $ana->id)
                ->where('academic_year_id', $this->year->id)
                ->value('nfc_ready')
        );
        $this->assertSame('04AABBCC', NfcAssignments::query()->where('uuid', $verifyId)->value('nfc_uid'));
    }

    public function test_latest_by_students_reports_tag_occupied_without_marking_ready(): void
    {
        $ana = $this->makeStudent('LOPE140102MOCRRNA3', 'Ana', 'Ok');
        $this->enroll($ana);
        $ana->update(['credential_id' => 'EST118-KEEP']);
        $owner = $this->makeStudent('GARC140102HOCRRNA3', 'Luis', 'Garcia');

        $created = $this->postJson('/api/nfc-assignments', [
            'student_id' => $ana->id,
            'action' => 'assign',
        ])->assertCreated();
        $this->assertSame('LOPE140102MOCRRNA3', $created->json('data.credential_id'));
        $this->assertSame('LOPE140102MOCRRNA3', $ana->fresh()->credential_id);
        $uuid = $created->json('data.job_id');

        Sanctum::actingAs($this->agentUser(), [ServiceAbility::PRINT_AGENT->value]);
        $this->getJson('/api/agent/nfc-jobs/next?agent_id=pc-recepcion')->assertOk();
        $this->postJson("/api/agent/nfc-jobs/{$uuid}/fail", [
            'agent_id' => 'pc-recepcion',
            'failure_code' => 'tag_occupied',
            'message' => 'La tarjeta ya tiene otro folio.',
            'read_back' => 'garc140102hocrrna3',
        ])->assertOk();

        Sanctum::actingAs($this->user);
        $this->getJson('/api/nfc-assignments/latest-by-students?student_ids='.$ana->id)
            ->assertOk()
            ->assertJsonPath('data.'.$ana->id.'.failure_code', 'tag_occupied')
            ->assertJsonPath('data.'.$ana->id.'.status', 'error')
            ->assertJsonPath('data.'.$ana->id.'.occupant.student_id', $owner->id)
            ->assertJsonPath('data.'.$ana->id.'.occupant.name', 'Luis Garcia')
            ->assertJsonPath('data.'.$ana->id.'.occupant.curp', 'GARC140102HOCRRNA3')
            ->assertJsonPath('data.'.$ana->id.'.status_message', 'La tarjeta ya pertenece a Luis Garcia (GARC140102HOCRRNA3).');

        $this->assertFalse(
            (bool) StudentCredentialTracking::query()->where('student_id', $ana->id)->value('nfc_ready')
        );
    }

    public function test_each_state_change_is_broadcast_to_the_panel(): void
    {
        Event::fake([NfcAssignmentUpdated::class]);

        $ana = $this->makeStudent('RIVE140103MOCRRNA5', 'Ana', 'Live');
        $this->enroll($ana);

        $uuid = $this->postJson('/api/nfc-assignments', [
            'student_id' => $ana->id,
            'action' => 'assign',
        ])->assertCreated()->json('data.job_id');

        Sanctum::actingAs($this->agentUser(), [ServiceAbility::PRINT_AGENT->value]);
        $this->getJson('/api/agent/nfc-jobs/next?agent_id=pc-recepcion')->assertOk();
        $this->getJson('/api/agent/nfc-jobs/next?agent_id=pc-recepcion')->assertOk();
        $this->postJson("/api/agent/nfc-jobs/{$uuid}/progress", [
            'agent_id' => 'pc-recepcion',
            'status' => 'card_detected',
            'nfc_uid' => '04AABBCC',
        ])->assertOk();
        $this->postJson("/api/agent/nfc-jobs/{$uuid}/complete", [
            'agent_id' => 'pc-recepcion',
            'nfc_uid' => '04AABBCC',
            'read_back' => $ana->fresh()->credential_id,
        ])->assertOk();

        $statuses = [];
        Event::assertDispatched(NfcAssignmentUpdated::class, function (NfcAssignmentUpdated $event) use ($uuid, &$statuses) {
            $this->assertSame($uuid, $event->job['job_id']);
            $statuses[] = $event->job['status'];

            return true;
        });
        // Re-claiming an already claimed job is not a state change.
        $this->assertSame(['pending', 'waiting_card', 'card_detected', 'completed'], $statuses);
    }

    public function test_student_without_a_valid_curp_cannot_be_assigned(): void
    {
        $ana = $this->makeStudent('NO-ES-CURP', 'Ana', 'Mala');
        $this->enroll($ana);

        $this->postJson('/api/nfc-assignments', [
            'student_id' => $ana->id,
            'action' => 'assign',
        ])->assertStatus(422);

        $this->assertNull($ana->fresh()->credential_id);
    }

    /**
     * @param  list<string>  $permissions
     */
    private function authUser(array $permissions): User
    {
        foreach ($permissions as $name) {
            Permission::findOrCreate($name, 'web');
        }

        $user = User::factory()->create([
            'email_verified_at' => now(),
        ]);
        $user->givePermissionTo($permissions);
        Sanctum::actingAs($user);

        return $user;
    }

    private function agentUser(): User
    {
        return User::factory()->create([
            'email_verified_at' => now(),
        ]);
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
        $grade = GradeLevel::query()->firstOrCreate(['name' => '1°']);
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
            'gender' => 'F',
            'birth_date' => '2014-01-01',
        ]);

        return Student::query()->create(['profile_id' => $profile->id]);
    }

    private function enroll(Student $student): Enrollment
    {
        return Enrollment::query()->create([
            'student_id' => $student->id,
            'class_group_id' => $this->group->id,
            'academic_year_id' => $this->year->id,
            'status' => EnrollmentStatus::Active,
        ]);
    }
}
