<?php

namespace Tests\Feature\Print;

use App\Enums\CredentialPrintReason;
use App\Enums\CredentialSideStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\PrintBatchStrategy;
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
use App\Models\Student;
use App\Models\StudentCredentialTracking;
use App\Models\User;
use App\Services\Print\CredentialPrintBackfill;
use App\Services\Print\CredentialPrintService;
use App\Services\Print\PrintJobService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CredentialPrintFlowTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private AcademicYear $year;

    private ClassGroup $group;

    private CardDesign $design;

    protected function setUp(): void
    {
        parent::setUp();
        Queue::fake();
        $this->user = $this->authUser(['view students', 'edit students']);
        [$this->year, $this->group] = $this->period();
        $this->design = CardDesign::query()->create([
            'user_id' => $this->user->id,
            'name' => 'Doble cara 2026',
            'audience' => 'students',
            'faces_mode' => 'double',
            'orientation' => 'landscape',
            'is_active' => true,
            'is_default' => true,
        ]);
    }

    public function test_fronts_then_backs_enqueues_backs_in_output_order(): void
    {
        $ana = $this->makeStudent('ANA140101MOCRRNA1', 'Ana', 'Lopez');
        $luis = $this->makeStudent('LUI140101HOCRRNA2', 'Luis', 'Perez');
        $this->enroll($ana);
        $this->enroll($luis);

        $service = app(CredentialPrintService::class);
        $jobs = app(PrintJobService::class);

        $batch = $service->createBatch(
            [$ana->id, $luis->id],
            $this->user,
            $this->design->uuid,
            PrintBatchStrategy::FrontsThenBacks
        );

        $cards = $batch['cards'];
        $this->assertCount(2, $cards);
        $this->assertTrue($cards->every(fn (CredentialPrint $c) => $c->front_status === CredentialSideStatus::Pending));
        $this->assertCount(2, PrintJob::query()->where('side_mode', 'front')->get());

        $fronts = PrintJob::query()->where('side_mode', 'front')->orderBy('id')->get();
        $jobs->markCompleted($fronts[0]);
        $this->travel(2)->seconds();
        $jobs->markCompleted($fronts[1]);

        $anaCard = CredentialPrint::query()->where('student_id', $ana->id)->first();
        $luisCard = CredentialPrint::query()->where('student_id', $luis->id)->first();
        $this->assertSame(CredentialSideStatus::Printed, $anaCard->front_status);
        $this->assertSame(CredentialSideStatus::Pending, $anaCard->back_status);
        $this->assertNull(StudentCredentialTracking::query()->where('student_id', $ana->id)->value('credential_printed'));

        $pending = $service->pending();
        $this->assertCount(1, $pending);
        $this->assertSame(2, $pending[0]['pending_count']);

        $backJobs = $service->enqueue([$anaCard->id, $luisCard->id], 'back', $this->user);
        $this->assertSame($luis->id, $backJobs[0]->student_id);
        $this->assertSame($ana->id, $backJobs[1]->student_id);

        foreach ($backJobs as $job) {
            $jobs->markCompleted($job);
        }

        $anaCard->refresh();
        $this->assertTrue($anaCard->isComplete());
        $this->assertTrue(
            (bool) StudentCredentialTracking::query()
                ->where('student_id', $ana->id)
                ->where('academic_year_id', $this->year->id)
                ->value('credential_printed')
        );
    }

    public function test_failed_front_is_excluded_from_pending_backs(): void
    {
        $ana = $this->makeStudent('ANA140102MOCRRNA3', 'Ana', 'Ok');
        $luis = $this->makeStudent('LUI140102HOCRRNA4', 'Luis', 'Fail');
        $this->enroll($ana);
        $this->enroll($luis);

        $service = app(CredentialPrintService::class);
        $jobs = app(PrintJobService::class);
        $service->createBatch(
            [$ana->id, $luis->id],
            $this->user,
            $this->design->uuid,
            PrintBatchStrategy::FrontsThenBacks
        );

        $anaJob = PrintJob::query()->where('student_id', $ana->id)->first();
        $luisJob = PrintJob::query()->where('student_id', $luis->id)->first();
        $jobs->markCompleted($anaJob);
        $luisJob->update(['attempts' => $luisJob->max_attempts]);
        $jobs->markFailed($luisJob, 'printer exploded');

        $pending = $service->pending();
        $ids = $pending[0]['cards']->pluck('student_id')->all();
        $this->assertSame([$ana->id], $ids);
        $this->assertSame(CredentialSideStatus::Failed, CredentialPrint::query()->where('student_id', $luis->id)->value('front_status'));
    }

    public function test_discard_records_the_user(): void
    {
        $ana = $this->makeStudent('ANA140103MOCRRNA5', 'Ana', 'Discard');
        $this->enroll($ana);
        $service = app(CredentialPrintService::class);
        $jobs = app(PrintJobService::class);
        $batch = $service->createBatch([$ana->id], $this->user, $this->design->uuid);
        $jobs->markCompleted(PrintJob::query()->first());

        $cards = $service->discard($this->user, null, $batch['batch_uuid'], 'Plástico dañado');
        $card = $cards->first();
        $this->assertSame($this->user->id, $card->discarded_by);
        $this->assertSame('Plástico dañado', $card->discard_reason);
        $this->assertSame(CredentialSideStatus::Cancelled, $card->back_status);
        $this->assertSame(CredentialSideStatus::Printed, $card->front_status);
        $this->assertTrue($service->pending()->isEmpty());
    }

    public function test_mark_side_completes_card_without_enqueueing_a_job(): void
    {
        $ana = $this->makeStudent('ANA140108MOCRRNB1', 'Ana', 'Manual');
        $this->enroll($ana);
        $service = app(CredentialPrintService::class);
        $jobs = app(PrintJobService::class);
        $service->createBatch([$ana->id], $this->user, $this->design->uuid);
        $jobs->markCompleted(PrintJob::query()->first());

        $card = CredentialPrint::query()->first();
        $jobCount = PrintJob::query()->count();
        $updated = $service->markSide($card->id, 'back', $this->user);

        $this->assertSame(CredentialSideStatus::Printed, $updated->back_status);
        $this->assertTrue($updated->isComplete());
        $this->assertSame($jobCount, PrintJob::query()->count());
        $this->assertTrue(
            (bool) StudentCredentialTracking::query()
                ->where('student_id', $ana->id)
                ->where('academic_year_id', $this->year->id)
                ->value('credential_printed')
        );
    }

    public function test_mark_front_on_pending_card_without_active_job(): void
    {
        $ana = $this->makeStudent('ANA140109MOCRRNB2', 'Ana', 'First');
        $luis = $this->makeStudent('LUI140109HOCRRNB3', 'Luis', 'Second');
        $this->enroll($ana);
        $this->enroll($luis);
        $service = app(CredentialPrintService::class);
        $batch = $service->createBatch(
            [$ana->id, $luis->id],
            $this->user,
            $this->design->uuid,
            PrintBatchStrategy::PerCard
        );
        $luisCard = $batch['cards']->firstWhere('student_id', $luis->id);
        $this->assertSame(CredentialSideStatus::Pending, $luisCard->front_status);
        $this->assertNull($luisCard->activeJob());

        $updated = $service->markSide($luisCard->id, 'front', $this->user);
        $this->assertSame(CredentialSideStatus::Printed, $updated->front_status);
        $this->assertSame(CredentialSideStatus::Pending, $updated->back_status);
        $this->assertFalse($updated->isComplete());
    }

    public function test_back_only_marks_front_printed_and_enqueues_back(): void
    {
        $ana = $this->makeStudent('ANA140110MOCRRNB4', 'Ana', 'LostHistory');
        $this->enroll($ana);
        $service = app(CredentialPrintService::class);
        $batch = $service->createBatch(
            [$ana->id],
            $this->user,
            $this->design->uuid,
            PrintBatchStrategy::BackOnly
        );

        $card = $batch['cards']->first();
        $this->assertSame(CredentialSideStatus::Printed, $card->front_status);
        $this->assertSame(CredentialSideStatus::Pending, $card->back_status);
        $this->assertSame(PrintBatchStrategy::BackOnly, $card->strategy);
        $this->assertSame(0, PrintJob::query()->where('side_mode', 'front')->count());
        $this->assertSame(1, PrintJob::query()->where('side_mode', 'back')->count());
        $this->assertNotNull($card->activeJob());
        $this->assertSame('back', $card->activeJob()?->side_mode);
    }

    public function test_cancel_job_fills_cancelled_by(): void
    {
        $ana = $this->makeStudent('ANA140104MOCRRNA6', 'Ana', 'Cancel');
        $this->enroll($ana);
        app(CredentialPrintService::class)->createBatch([$ana->id], $this->user, $this->design->uuid);
        $job = PrintJob::query()->first();
        $cancelled = app(PrintJobService::class)->cancel($job, $this->user);

        $this->assertSame($this->user->id, $cancelled->cancelled_by);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame(PrintJobStatus::Cancelled, $cancelled->status);
        $card = CredentialPrint::query()->first();
        $this->assertSame(CredentialSideStatus::Cancelled, $card->front_status);
        $this->assertSame(CredentialSideStatus::Cancelled, $card->back_status);
    }

    public function test_http_create_batch_and_pending_endpoints(): void
    {
        $ana = $this->makeStudent('ANA140105MOCRRNA7', 'Ana', 'Http');
        $this->enroll($ana);

        $create = $this->postJson('/api/credential-prints', [
            'student_ids' => [$ana->id],
            'template_key' => $this->design->uuid,
            'strategy' => 'fronts_then_backs',
        ]);
        $create->assertCreated();
        $batchUuid = $create->json('data.batch_uuid');
        $this->assertNotEmpty($batchUuid);

        Queue::assertPushed(RenderStudentCardJob::class);

        $job = PrintJob::query()->first();
        app(PrintJobService::class)->markCompleted($job);

        $this->getJson('/api/credential-prints/pending')
            ->assertOk()
            ->assertJsonPath('data.0.pending_count', 1)
            ->assertJsonPath('data.0.batch_uuid', $batchUuid);

        $cardId = $create->json('data.cards.0.id');
        $this->postJson('/api/credential-prints/enqueue', [
            'credential_print_ids' => [$cardId],
            'side' => 'back',
        ])->assertOk()->assertJsonPath('data.0.side_mode', 'back');
    }

    public function test_backfill_links_back_job_to_matching_front(): void
    {
        $ana = $this->makeStudent('ANA140106MOCRRNA8', 'Ana', 'Legacy');
        $this->enroll($ana);

        $front = PrintJob::query()->create([
            'student_id' => $ana->id,
            'printer_id' => PrintJobService::DEFAULT_PRINTER_ID,
            'template_key' => $this->design->uuid,
            'card_design_id' => $this->design->id,
            'side_mode' => 'front',
            'status' => PrintJobStatus::Completed,
            'payload_json' => [
                'faces_mode' => 'double',
                'design_key' => $this->design->uuid,
            ],
            'created_by' => $this->user->id,
            'academic_year_id' => $this->year->id,
            'completed_at' => now()->subMinute(),
        ]);
        $back = PrintJob::query()->create([
            'student_id' => $ana->id,
            'printer_id' => PrintJobService::DEFAULT_PRINTER_ID,
            'template_key' => $this->design->uuid,
            'card_design_id' => $this->design->id,
            'side_mode' => 'back',
            'status' => PrintJobStatus::Completed,
            'payload_json' => [
                'faces_mode' => 'double',
                'design_key' => $this->design->uuid,
            ],
            'created_by' => $this->user->id,
            'academic_year_id' => $this->year->id,
            'completed_at' => now(),
        ]);

        app(CredentialPrintBackfill::class)->run();

        $front->refresh();
        $back->refresh();
        $this->assertNotNull($front->credential_print_id);
        $this->assertSame($front->credential_print_id, $back->credential_print_id);
        $card = CredentialPrint::query()->find($front->credential_print_id);
        $this->assertSame(CredentialSideStatus::Printed, $card->front_status);
        $this->assertSame(CredentialSideStatus::Printed, $card->back_status);
        $this->assertNotNull($card->completed_at);
        $this->assertSame(CredentialPrintReason::Migrated, $card->reason);
    }

    public function test_backfill_marks_orphan_double_front_as_pending_back(): void
    {
        $ana = $this->makeStudent('ANA140107MOCRRNA9', 'Ana', 'Orphan');
        $this->enroll($ana);
        PrintJob::query()->create([
            'student_id' => $ana->id,
            'printer_id' => PrintJobService::DEFAULT_PRINTER_ID,
            'template_key' => $this->design->uuid,
            'card_design_id' => $this->design->id,
            'side_mode' => 'front',
            'status' => PrintJobStatus::Completed,
            'payload_json' => [
                'faces_mode' => 'double',
                'design_key' => $this->design->uuid,
            ],
            'created_by' => $this->user->id,
            'academic_year_id' => $this->year->id,
            'completed_at' => now(),
        ]);

        app(CredentialPrintBackfill::class)->run();

        $card = CredentialPrint::query()->first();
        $this->assertSame(CredentialSideStatus::Printed, $card->front_status);
        $this->assertSame(CredentialSideStatus::Pending, $card->back_status);
        $this->assertSame(CredentialPrintReason::Migrated, $card->reason);
        $pending = app(CredentialPrintService::class)->pending();
        $this->assertSame(1, $pending[0]['pending_count']);
        $this->assertSame('migrated', $pending[0]['reason']);
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
