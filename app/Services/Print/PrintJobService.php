<?php

namespace App\Services\Print;

use App\Enums\EnrollmentStatus;
use App\Enums\PrintBatchStrategy;
use App\Enums\PrintJobStatus;
use App\Jobs\RenderStudentCardJob;
use App\Models\CardDesign;
use App\Models\CredentialPrint;
use App\Models\PrintJob;
use App\Models\Student;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PrintJobService
{
    public const DEFAULT_PRINTER_ID = 'zc300-recepcion';

    public const HEARTBEAT_TTL_SECONDS = 90;

    public const PAUSE_TTL_SECONDS = 86400;

    /**
     * heartbeatStatus() can call pauseQueue(), which publishes. This stops that loop.
     */
    private bool $publishingAgentStatus = false;

    public function __construct(
        private readonly StudentCardRenderService $cards,
        private readonly CardTemplateService $templates
    ) {}

    /**
     * Create a single side job for an existing credential print card.
     */
    public function createJobForCard(
        CredentialPrint $card,
        User $creator,
        string $sideMode = 'front',
        string $printerId = self::DEFAULT_PRINTER_ID
    ): PrintJob {
        $student = $this->studentForPrint((int) $card->student_id);
        $design = $card->cardDesign ?? CardDesign::query()->find($card->card_design_id);
        if (! $design) {
            throw ValidationException::withMessages([
                'card_design_id' => ['La tarjeta no tiene un diseño asociado.'],
            ]);
        }

        $facesMode = ($card->faces_mode ?? $design->faces_mode ?? 'single') === 'double' ? 'double' : 'single';
        $resolvedSide = $this->resolveSideMode($sideMode, $facesMode);

        $enrollment = $student->currentEnrollment
            ?? $student->enrollments()->where('status', EnrollmentStatus::Active)->first();

        $orientation = $design->orientation === 'portrait' ? 'portrait' : 'landscape';
        $payload = $this->cards->payloadFromStudent($student);
        $payload['orientation'] = $orientation;
        $payload['faces_mode'] = $facesMode;
        $payload['design_key'] = $design->uuid;
        $payload['design_label'] = $design->name;

        $job = PrintJob::query()->create([
            'student_id' => $student->id,
            'credential_print_id' => $card->id,
            'printer_id' => $printerId,
            'template_key' => $design->uuid,
            'card_design_id' => $design->id,
            'side_mode' => $resolvedSide,
            'status' => PrintJobStatus::Pending,
            'payload_json' => $payload,
            'created_by' => $creator->id,
            'academic_year_id' => $card->academic_year_id ?? $enrollment?->academic_year_id,
        ]);

        RenderStudentCardJob::dispatch($job->id)->afterCommit();

        return $job->fresh() ?? $job;
    }

    /**
     * @param  array<int, int>  $studentIds
     * @return array<int, PrintJob>
     *
     * @deprecated Use CredentialPrintService::createBatch
     */
    public function createJobs(
        array $studentIds,
        User $creator,
        string $printerId = self::DEFAULT_PRINTER_ID,
        string $templateKey = 'student-card-v1',
        string $sideMode = 'front'
    ): array {
        if ($sideMode === 'back') {
            throw ValidationException::withMessages([
                'side_mode' => ['Los reversos se encolan desde el lote de tarjetas, no desde este endpoint.'],
            ]);
        }

        $batch = app(CredentialPrintService::class)->createBatch(
            $studentIds,
            $creator,
            $templateKey,
            PrintBatchStrategy::FrontsThenBacks,
            $printerId
        );

        return $batch['cards']
            ->map(fn (CredentialPrint $card) => $card->printJobs->sortBy('id')->last())
            ->filter()
            ->values()
            ->all();
    }

    /**
     * Resolve design + faces_mode per student without creating jobs.
     *
     * @param  array<int, int>  $studentIds
     * @return array<int, array<string, mixed>>
     */
    public function resolveDesigns(array $studentIds, string $templateKey = 'student-card-v1'): array
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
        $rows = [];

        foreach ($studentIds as $studentId) {
            $student = $this->loadStudentForPrint($studentId);
            $design = $this->resolveDesignForStudent($student, $templateKey);
            $facesMode = ($design->faces_mode ?? 'single') === 'double' ? 'double' : 'single';
            $profile = $student->profile;

            $rows[] = [
                'student_id' => $student->id,
                'student_name' => trim(($profile?->first_name ?? '').' '.($profile?->last_name ?? '')),
                'design_key' => $design->uuid,
                'design_label' => $design->name,
                'faces_mode' => $facesMode,
                'orientation' => $design->orientation === 'portrait' ? 'portrait' : 'landscape',
            ];
        }

        return $rows;
    }

    public function listForAdmin(Request $request): LengthAwarePaginator
    {
        // kept for BC — use listFiltered
        return $this->listFiltered((int) $request->integer('per_page', 20));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function listFiltered(int $perPage = 20, array $filters = []): LengthAwarePaginator
    {
        $q = PrintJob::query()
            ->with(['student.profile:id,first_name,last_name'])
            ->orderByDesc('id');

        if (! empty($filters['student_ids']) && is_array($filters['student_ids'])) {
            $q->whereIn('student_id', array_map('intval', $filters['student_ids']));
        }

        if (! empty($filters['status'])) {
            $statuses = is_array($filters['status'])
                ? $filters['status']
                : explode(',', (string) $filters['status']);
            $q->whereIn('status', $statuses);
        }

        if (! empty($filters['active_only'])) {
            $q->whereIn('status', [
                PrintJobStatus::Pending->value,
                PrintJobStatus::Ready->value,
                PrintJobStatus::Claimed->value,
                PrintJobStatus::Printing->value,
            ]);
        }

        return $q->paginate($perPage);
    }

    /**
     * Latest job per student (for credential table status badges).
     *
     * @param  array<int, int>  $studentIds
     * @return array<int, PrintJob>
     */
    public function latestByStudentIds(array $studentIds): array
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
        if ($studentIds === []) {
            return [];
        }

        $jobs = PrintJob::query()
            ->whereIn('student_id', $studentIds)
            ->orderByDesc('id')
            ->get();

        $latest = [];
        foreach ($jobs as $job) {
            if (! isset($latest[$job->student_id])) {
                $latest[$job->student_id] = $job;
            }
        }

        return $latest;
    }

    public function cancel(PrintJob $job, ?User $actor = null): PrintJob
    {
        if (in_array($job->status, [PrintJobStatus::Completed, PrintJobStatus::Cancelled], true)) {
            throw ValidationException::withMessages([
                'status' => ['El trabajo ya no se puede cancelar.'],
            ]);
        }

        return DB::transaction(function () use ($job, $actor) {
            $job->update([
                'status' => PrintJobStatus::Cancelled,
                'last_error' => 'Cancelado por administrador',
                'cancelled_by' => $actor?->id,
                'cancelled_at' => now(),
            ]);
            $fresh = $job->fresh() ?? $job;
            $this->syncCard($fresh);

            return $fresh;
        });
    }

    public function claimNext(string $printerId, string $agentId): ?PrintJob
    {
        if ($this->isQueuePaused($printerId)) {
            return null;
        }

        return DB::transaction(function () use ($printerId, $agentId) {
            /** @var PrintJob|null $owned */
            $owned = PrintJob::query()
                ->where('printer_id', $printerId)
                ->where('claimed_by', $agentId)
                ->whereIn('status', [PrintJobStatus::Claimed, PrintJobStatus::Printing])
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if ($owned) {
                return $owned;
            }

            /** @var PrintJob|null $job */
            $job = PrintJob::query()
                ->where('printer_id', $printerId)
                ->where('status', PrintJobStatus::Ready)
                ->orderByDesc('priority')
                ->orderBy('id')
                ->lockForUpdate()
                ->first();

            if (! $job) {
                return null;
            }

            $job->update([
                'status' => PrintJobStatus::Claimed,
                'claimed_by' => $agentId,
                'claimed_at' => now(),
                'attempts' => $job->attempts + 1,
            ]);

            return $job->fresh();
        });
    }

    public function markPrinting(PrintJob $job): PrintJob
    {
        $job->update([
            'status' => PrintJobStatus::Printing,
            'started_at' => now(),
        ]);

        return $job->fresh();
    }

    public function markCompleted(PrintJob $job): PrintJob
    {
        return DB::transaction(function () use ($job) {
            $job->update([
                'status' => PrintJobStatus::Completed,
                'completed_at' => now(),
                'last_error' => null,
            ]);
            $fresh = $job->fresh() ?? $job;
            $this->syncCard($fresh);

            return $fresh;
        });
    }

    public function markFailed(PrintJob $job, string $error): PrintJob
    {
        $media = $this->isMediaError($error);
        $retry = $media || $job->attempts < $job->max_attempts;

        $job->update([
            'status' => $retry ? PrintJobStatus::Ready : PrintJobStatus::Failed,
            'last_error' => $error,
            'claimed_by' => null,
            'claimed_at' => null,
            'attempts' => $media ? max(0, $job->attempts - 1) : $job->attempts,
        ]);

        $fresh = $job->fresh() ?? $job;
        if (! $retry) {
            $this->syncCard($fresh);
        }

        if ($media) {
            $this->pauseQueue((string) $job->printer_id, $error);
        }

        return $fresh;
    }

    public function recordHeartbeat(string $printerId, string $agentId, array $meta = []): array
    {
        $payload = [
            'printer_id' => $printerId,
            'agent_id' => $agentId,
            'meta' => $meta,
            'seen_at' => now()->toIso8601String(),
        ];

        Cache::put($this->heartbeatCacheKey($printerId), $payload, self::HEARTBEAT_TTL_SECONDS);

        $shouldPause = data_get($meta, 'blocks_queue') || data_get($meta, 'out_of_cards') || data_get($meta, 'paused');
        if ($shouldPause && $this->hasActiveJobs($printerId)) {
            $this->pauseQueue(
                $printerId,
                (string) (data_get($meta, 'user_message') ?: data_get($meta, 'pause_reason') ?: data_get($meta, 'issue') ?: 'Impresora bloqueada')
            );
        }

        $this->publishAgentStatus($printerId);

        return $payload;
    }

    public function heartbeatStatus(?string $printerId = null): array
    {
        $printerId ??= self::DEFAULT_PRINTER_ID;
        $cached = Cache::get($this->heartbeatCacheKey($printerId));

        if (! is_array($cached)) {
            return [
                'printer_id' => $printerId,
                'online' => false,
                'seen_at' => null,
                'agent_id' => null,
                'meta' => null,
                'cards_available' => null,
                'out_of_cards' => null,
                'out_of_ribbon' => null,
                'ribbon_low' => null,
                'cleaning_required' => null,
                'cleaning_due' => null,
                'card_jam' => null,
                'drawer_open' => null,
                'offline' => null,
                'blocks_queue' => null,
                'issue' => null,
                'user_message' => null,
                'queue_paused' => $this->isQueuePaused($printerId),
                'pause_reason' => $this->pauseReason($printerId),
            ];
        }

        $meta = $cached['meta'] ?? null;
        if (data_get($meta, 'paused') || data_get($meta, 'queue_paused')) {
            $this->pauseQueue(
                $printerId,
                (string) (data_get($meta, 'user_message') ?: data_get($meta, 'pause_reason') ?: data_get($meta, 'issue') ?: 'paused')
            );
        }

        return [
            'printer_id' => $printerId,
            'online' => true,
            'seen_at' => $cached['seen_at'] ?? null,
            'agent_id' => $cached['agent_id'] ?? null,
            'meta' => $meta,
            'cards_available' => (bool) data_get($meta, 'cards_available', true),
            'out_of_cards' => (bool) data_get($meta, 'out_of_cards', false),
            'out_of_ribbon' => (bool) data_get($meta, 'out_of_ribbon', false),
            'ribbon_low' => (bool) data_get($meta, 'ribbon_low', false),
            'cleaning_required' => (bool) data_get($meta, 'cleaning_required', false),
            'cleaning_due' => (bool) data_get($meta, 'cleaning_due', false),
            'card_jam' => (bool) data_get($meta, 'card_jam', false),
            'drawer_open' => (bool) data_get($meta, 'drawer_open', false),
            'offline' => (bool) data_get($meta, 'offline', false),
            'blocks_queue' => (bool) data_get($meta, 'blocks_queue', false),
            'issue' => data_get($meta, 'issue'),
            'user_message' => data_get($meta, 'user_message'),
            'queue_paused' => $this->isQueuePaused($printerId),
            'pause_reason' => $this->pauseReason($printerId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function queueSnapshot(string $printerId = self::DEFAULT_PRINTER_ID): array
    {
        $jobs = PrintJob::query()
            ->with(['student.profile:id,first_name,last_name'])
            ->where('printer_id', $printerId)
            ->whereIn('status', [
                PrintJobStatus::Pending->value,
                PrintJobStatus::Ready->value,
                PrintJobStatus::Claimed->value,
                PrintJobStatus::Printing->value,
                PrintJobStatus::Failed->value,
            ])
            ->orderBy('id')
            ->limit(200)
            ->get();

        return [
            'printer_id' => $printerId,
            'paused' => $this->isQueuePaused($printerId),
            'pause_reason' => $this->pauseReason($printerId),
            'agent' => $this->heartbeatStatus($printerId),
            'jobs' => $jobs,
        ];
    }

    /**
     * @return array<int, PrintJob>
     */
    public function cancelQueue(string $printerId = self::DEFAULT_PRINTER_ID, ?User $actor = null): array
    {
        return DB::transaction(function () use ($printerId, $actor) {
            $jobs = PrintJob::query()
                ->where('printer_id', $printerId)
                ->whereIn('status', [
                    PrintJobStatus::Pending->value,
                    PrintJobStatus::Ready->value,
                    PrintJobStatus::Claimed->value,
                    PrintJobStatus::Printing->value,
                ])
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            foreach ($jobs as $job) {
                $job->update([
                    'status' => PrintJobStatus::Cancelled,
                    'last_error' => 'Cancelado por administrador',
                    'claimed_by' => null,
                    'claimed_at' => null,
                    'cancelled_by' => $actor?->id,
                    'cancelled_at' => now(),
                ]);
                $this->syncCard($job->fresh() ?? $job);
            }

            $this->clearPause($printerId);

            return $jobs->map(fn (PrintJob $job) => $job->fresh())->filter()->values()->all();
        });
    }

    /**
     * @return array<int, PrintJob>
     */
    public function resumeQueue(string $printerId = self::DEFAULT_PRINTER_ID): array
    {
        $this->clearPause($printerId);

        $failed = PrintJob::query()
            ->where('printer_id', $printerId)
            ->where('status', PrintJobStatus::Failed)
            ->orderBy('id')
            ->get();

        foreach ($failed as $job) {
            if (! $this->isMediaError((string) $job->last_error)) {
                continue;
            }

            $job->update([
                'status' => PrintJobStatus::Ready,
                'last_error' => null,
                'claimed_by' => null,
                'claimed_at' => null,
                'attempts' => 0,
            ]);
        }

        return PrintJob::query()
            ->where('printer_id', $printerId)
            ->whereIn('status', [
                PrintJobStatus::Pending->value,
                PrintJobStatus::Ready->value,
                PrintJobStatus::Claimed->value,
                PrintJobStatus::Printing->value,
            ])
            ->orderBy('id')
            ->get()
            ->all();
    }

    public function pauseQueue(string $printerId, string $reason): void
    {
        Cache::put($this->pauseCacheKey($printerId), [
            'paused' => true,
            'reason' => $reason,
            'paused_at' => now()->toIso8601String(),
        ], self::PAUSE_TTL_SECONDS);

        $this->publishAgentStatus($printerId);
    }

    public function isQueuePaused(string $printerId): bool
    {
        $cached = Cache::get($this->pauseCacheKey($printerId));

        return is_array($cached) && ($cached['paused'] ?? false);
    }

    public function pauseReason(string $printerId): ?string
    {
        $cached = Cache::get($this->pauseCacheKey($printerId));

        return is_array($cached) ? ($cached['reason'] ?? null) : null;
    }

    public function clearPause(string $printerId): void
    {
        Cache::forget($this->pauseCacheKey($printerId));

        $this->publishAgentStatus($printerId);
    }

    /**
     * Broadcast only when the panel would show something different.
     * seen_at changes on every heartbeat and is not part of the signature.
     */
    private function publishAgentStatus(string $printerId): void
    {
        if ($this->publishingAgentStatus) {
            return;
        }

        $this->publishingAgentStatus = true;
        try {
            $status = $this->heartbeatStatus($printerId);
            $signature = $this->agentStatusSignature($status);
            $key = 'print_agent_broadcast:'.$printerId;
            if (Cache::get($key) === $signature) {
                return;
            }

            Cache::put($key, $signature, self::PAUSE_TTL_SECONDS);
            app(PrintStatusBroadcaster::class)->agent($status);
        } finally {
            $this->publishingAgentStatus = false;
        }
    }

    /**
     * @param  array<string, mixed>  $status
     */
    private function agentStatusSignature(array $status): string
    {
        $slice = [];
        foreach ([
            'online',
            'queue_paused',
            'pause_reason',
            'cards_available',
            'out_of_cards',
            'out_of_ribbon',
            'ribbon_low',
            'cleaning_required',
            'cleaning_due',
            'card_jam',
            'drawer_open',
            'offline',
            'blocks_queue',
            'issue',
            'user_message',
        ] as $field) {
            $slice[$field] = $status[$field] ?? null;
        }

        return json_encode($slice) ?: '';
    }

    private function syncCard(PrintJob $job): void
    {
        if (! $job->credential_print_id) {
            return;
        }

        app(CredentialPrintService::class)->syncFromJob($job);
    }

    public function studentForPrint(int $studentId): Student
    {
        return $this->loadStudentForPrint($studentId);
    }

    private function loadStudentForPrint(int $studentId): Student
    {
        $student = Student::query()
            ->with([
                'profile.address',
                'guardians.profile',
                'currentEnrollment.classGroup.gradeLevel',
                'workshopEnrollments.workshop',
            ])
            ->find($studentId);

        if (! $student?->profile) {
            throw ValidationException::withMessages([
                'student_ids' => ["Alumno {$studentId} no encontrado o sin perfil."],
            ]);
        }

        return $student;
    }

    public function resolveDesignForStudent(Student $student, ?string $preferredKey = null): CardDesign
    {
        $gradeId = $student->currentEnrollment?->classGroup?->grade_level_id;

        if (is_string($preferredKey) && trim($preferredKey) !== '') {
            try {
                $design = $this->templates->findDesign($preferredKey);
                if ($this->designAppliesToGrade($design, $gradeId)) {
                    return $design;
                }
            } catch (\Throwable) {
                // Fall through to grade default.
            }
        }

        $base = CardDesign::query()
            ->where('is_active', true)
            ->where('audience', 'students');

        if ($gradeId) {
            $match = (clone $base)
                ->where('grade_level_id', $gradeId)
                ->orderByDesc('is_default')
                ->orderByDesc('id')
                ->first();
            if ($match) {
                return $match;
            }
        }

        $fallback = (clone $base)
            ->whereNull('grade_level_id')
            ->orderByDesc('is_default')
            ->orderByDesc('id')
            ->first();

        if ($fallback) {
            return $fallback;
        }

        throw ValidationException::withMessages([
            'template_key' => ["El alumno {$student->id} no tiene un diseño de credencial aplicado."],
        ]);
    }

    private function designAppliesToGrade(CardDesign $design, mixed $gradeId): bool
    {
        if (! $design->grade_level_id) {
            return true;
        }

        return $gradeId !== null && (int) $design->grade_level_id === (int) $gradeId;
    }

    private function resolveSideMode(string $requested, string $facesMode): string
    {
        $requested = in_array($requested, ['front', 'back'], true) ? $requested : 'front';

        if ($facesMode === 'double') {
            return $requested;
        }

        if ($requested === 'back') {
            throw ValidationException::withMessages([
                'side_mode' => ['Este diseño solo tiene una cara. Elige o crea un diseño de dos caras.'],
            ]);
        }

        return 'front';
    }

    private function isMediaError(string $error): bool
    {
        $hay = strtolower($error);

        return str_contains($hay, 'out_of_cards')
            || str_contains($hay, 'out of card')
            || str_contains($hay, 'no card')
            || str_contains($hay, 'feeder empty')
            || str_contains($hay, 'out_of_ribbon')
            || str_contains($hay, 'out of ribbon')
            || str_contains($hay, 'invalid ribbon')
            || str_contains($hay, 'card_jam')
            || str_contains($hay, 'card jam')
            || str_contains($hay, 'ribbon jam')
            || str_contains($hay, 'drawer_open')
            || str_contains($hay, 'drawer open')
            || str_contains($hay, 'cleaning')
            || str_contains($hay, 'offline')
            || str_contains($hay, 'blocks_queue');
    }

    private function hasActiveJobs(string $printerId): bool
    {
        return PrintJob::query()
            ->where('printer_id', $printerId)
            ->whereIn('status', [
                PrintJobStatus::Pending->value,
                PrintJobStatus::Ready->value,
                PrintJobStatus::Claimed->value,
                PrintJobStatus::Printing->value,
            ])
            ->exists();
    }

    private function pauseCacheKey(string $printerId): string
    {
        return 'print_queue_paused:'.$printerId;
    }

    private function heartbeatCacheKey(string $printerId): string
    {
        return 'print_agent_heartbeat:'.$printerId;
    }
}
