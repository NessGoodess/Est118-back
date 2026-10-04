<?php

namespace Tests\Feature\Print;

use App\Enums\CredentialPrintReason;
use App\Enums\CredentialSideStatus;
use App\Enums\EnrollmentStatus;
use App\Enums\PrintBatchStrategy;
use App\Enums\PrintJobStatus;
use App\Events\CredentialPrintUpdated;
use App\Events\PrintJobUpdated;
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
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
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

    public function test_http_cancel_job_endpoint_records_the_user(): void
    {
        $ana = $this->makeStudent('ANA140108MOCRRNB1', 'Ana', 'HttpCancel');
        $this->enroll($ana);
        app(CredentialPrintService::class)->createBatch([$ana->id], $this->user, $this->design->uuid);
        $job = PrintJob::query()->first();

        $this->postJson("/api/print-jobs/{$job->uuid}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', PrintJobStatus::Cancelled->value);

        $this->assertSame($this->user->id, $job->fresh()->cancelled_by);
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
        $create->assertAccepted();
        $batchUuid = $create->json('data.batch_uuid');
        $this->assertNotEmpty($batchUuid);
        $create->assertJsonPath('data.summary.total', 1);
        $create->assertJsonPath('data.summary.rendering', 1);
        $create->assertJsonPath('data.summary.ready', 0);

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

    public function test_resending_a_student_with_an_open_card_is_skipped(): void
    {
        $ana = $this->makeStudent('ANA140111MOCRRNC1', 'Ana', 'Dup');
        $this->enroll($ana);
        $service = app(CredentialPrintService::class);

        $first = $service->createBatch([$ana->id], $this->user, $this->design->uuid);
        $this->assertCount(1, $first['cards']);
        $this->assertSame([], $first['skipped']);

        $second = $service->createBatch([$ana->id], $this->user, $this->design->uuid);
        $this->assertNull($second['batch_uuid']);
        $this->assertTrue($second['cards']->isEmpty());
        $this->assertSame('in_queue', $second['skipped'][0]['reason']);
        $this->assertSame($ana->id, $second['skipped'][0]['student_id']);
        $this->assertSame('Ana Dup', $second['skipped'][0]['student_name']);
        $this->assertSame(1, CredentialPrint::query()->where('student_id', $ana->id)->count());
    }

    public function test_mixed_batch_skips_only_the_student_with_an_open_card(): void
    {
        $ana = $this->makeStudent('ANA140112MOCRRNC2', 'Ana', 'Open');
        $luis = $this->makeStudent('LUI140112HOCRRNC3', 'Luis', 'New');
        $sara = $this->makeStudent('SAR140112MOCRRNC4', 'Sara', 'New');
        $this->enroll($ana);
        $this->enroll($luis);
        $this->enroll($sara);

        $service = app(CredentialPrintService::class);
        $jobs = app(PrintJobService::class);
        $service->createBatch([$ana->id], $this->user, $this->design->uuid);
        $jobs->markCompleted(PrintJob::query()->where('student_id', $ana->id)->first());

        $batch = $service->createBatch(
            [$ana->id, $luis->id, $sara->id],
            $this->user,
            $this->design->uuid
        );

        $this->assertCount(2, $batch['cards']);
        $this->assertCount(1, $batch['skipped']);
        $this->assertSame($ana->id, $batch['skipped'][0]['student_id']);
        $this->assertSame('needs_back', $batch['skipped'][0]['reason']);
        $this->assertSame(2, PrintJob::query()->where('side_mode', 'front')->where('status', '!=', PrintJobStatus::Completed)->count());
    }

    public function test_finished_or_discarded_card_does_not_block_a_new_batch(): void
    {
        $ana = $this->makeStudent('ANA140113MOCRRNC5', 'Ana', 'Done');
        $luis = $this->makeStudent('LUI140113HOCRRNC6', 'Luis', 'Tossed');
        $this->enroll($ana);
        $this->enroll($luis);
        $service = app(CredentialPrintService::class);
        $jobs = app(PrintJobService::class);

        $service->createBatch([$ana->id], $this->user, $this->design->uuid);
        $jobs->markCompleted(PrintJob::query()->where('student_id', $ana->id)->first());
        $service->markSide(CredentialPrint::query()->where('student_id', $ana->id)->first()->id, 'back', $this->user);

        $luisBatch = $service->createBatch([$luis->id], $this->user, $this->design->uuid);
        $service->discard($this->user, null, $luisBatch['batch_uuid'], 'Prueba');

        $again = $service->createBatch([$ana->id, $luis->id], $this->user, $this->design->uuid);
        $this->assertCount(2, $again['cards']);
        $this->assertSame([], $again['skipped']);
    }

    public function test_unique_index_rejects_a_second_open_card_for_the_same_student(): void
    {
        $ana = $this->makeStudent('ANA140114MOCRRNC7', 'Ana', 'Index');
        $this->enroll($ana);
        $service = app(CredentialPrintService::class);
        $service->createBatch([$ana->id], $this->user, $this->design->uuid);

        $existing = CredentialPrint::query()->where('student_id', $ana->id)->first();
        $this->expectException(QueryException::class);
        CredentialPrint::query()->create($existing->only([
            'student_id', 'academic_year_id', 'card_design_id', 'faces_mode',
            'front_status', 'back_status', 'strategy', 'reason',
        ]) + ['batch_uuid' => (string) \Illuminate\Support\Str::uuid(), 'created_by' => $this->user->id]);
    }

    public function test_dedupe_command_keeps_one_open_card_per_student(): void
    {
        $ana = $this->makeStudent('ANA140115MOCRRNC8', 'Ana', 'Dedupe');
        $this->enroll($ana);
        $service = app(CredentialPrintService::class);
        $jobs = app(PrintJobService::class);

        $batch = $service->createBatch([$ana->id], $this->user, $this->design->uuid);
        $jobs->markCompleted(PrintJob::query()->where('student_id', $ana->id)->first());
        $kept = CredentialPrint::query()->where('student_id', $ana->id)->first();

        // El índice único existe para impedir esto; se quita solo para simular
        // duplicados que quedaron antes de la migración.
        Schema::table('credential_prints', function ($table) {
            $table->dropUnique(['open_student_id']);
        });
        $duplicate = CredentialPrint::query()->create($kept->only([
            'student_id', 'academic_year_id', 'card_design_id', 'faces_mode',
            'strategy', 'reason', 'created_by',
        ]) + [
            'batch_uuid' => (string) \Illuminate\Support\Str::uuid(),
            'front_status' => CredentialSideStatus::Pending,
            'back_status' => CredentialSideStatus::Pending,
        ]);

        $this->assertSame(2, CredentialPrint::query()->open()->where('student_id', $ana->id)->count());

        Artisan::call('credential-prints:dedupe-open', ['--dry-run' => true]);
        $this->assertSame(2, CredentialPrint::query()->open()->where('student_id', $ana->id)->count());

        Artisan::call('credential-prints:dedupe-open');
        $open = CredentialPrint::query()->open()->where('student_id', $ana->id)->get();
        $this->assertCount(1, $open);
        $this->assertSame($kept->id, $open->first()->id);
        $this->assertNotNull($duplicate->fresh()->discarded_at);
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

    public function test_completing_a_front_broadcasts_the_job_and_the_card(): void
    {
        $ana = $this->makeStudent('ANA140111MOCRRNB5', 'Ana', 'Realtime');
        $this->enroll($ana);
        app(CredentialPrintService::class)->createBatch(
            [$ana->id],
            $this->user,
            $this->design->uuid
        );
        $job = PrintJob::query()->firstOrFail();

        Event::fake([PrintJobUpdated::class, CredentialPrintUpdated::class]);
        app(PrintJobService::class)->markCompleted($job);

        Event::assertDispatched(PrintJobUpdated::class, function (PrintJobUpdated $event) use ($job) {
            return ($event->job['id'] ?? null) === $job->id
                && ($event->job['status'] ?? null) === PrintJobStatus::Completed->value
                && ($event->job['batch_uuid'] ?? null) !== null
                && ! array_key_exists('payload', $event->job)
                && ! array_key_exists('payload_json', $event->job);
        });
        Event::assertDispatched(CredentialPrintUpdated::class, function (CredentialPrintUpdated $event) use ($job) {
            $jobs = $event->card['jobs'] ?? [];

            return ($event->card['id'] ?? null) === $job->credential_print_id
                && ($event->card['front_status'] ?? null) === CredentialSideStatus::Printed->value
                && is_array($jobs)
                && collect($jobs)->every(fn ($row) => is_array($row) && ! array_key_exists('payload', $row));
        });
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
