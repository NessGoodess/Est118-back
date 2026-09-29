<?php

namespace App\Http\Controllers\Print;

use App\Enums\PrintBatchStrategy;
use App\Enums\PrintJobStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Print\DiscardCredentialPrintsRequest;
use App\Http\Requests\Print\EnqueueCredentialPrintsRequest;
use App\Http\Requests\Print\MarkCredentialPrintSideRequest;
use App\Http\Requests\Print\StoreCredentialPrintsRequest;
use App\Models\CredentialPrint;
use App\Models\PrintJob;
use App\Services\Print\CredentialPrintService;
use App\Services\Print\PrintJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CredentialPrintController extends Controller
{
    public function __construct(
        private readonly CredentialPrintService $cards
    ) {}

    public function store(StoreCredentialPrintsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $strategy = PrintBatchStrategy::tryFrom((string) ($data['strategy'] ?? ''))
            ?? PrintBatchStrategy::FrontsThenBacks;

        $batch = $this->cards->createBatch(
            $data['student_ids'],
            $request->user(),
            $data['template_key'] ?? 'student-card-v1',
            $strategy,
            $data['printer_id'] ?? PrintJobService::DEFAULT_PRINTER_ID
        );

        return response()->json([
            'success' => true,
            'data' => $this->serializeBatch($batch),
        ], 201);
    }

    public function batch(string $batchUuid): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->serializeBatch($this->cards->batch($batchUuid)),
        ]);
    }

    public function pending(): JsonResponse
    {
        $groups = $this->cards->pending()->map(function (array $group) {
            return [
                'batch_uuid' => $group['batch_uuid'],
                'strategy' => $group['strategy'],
                'reason' => $group['reason'],
                'created_at' => $group['created_at'],
                'pending_count' => $group['pending_count'],
                'cards' => $group['cards']->map(fn (CredentialPrint $card) => $this->serializeCard($card))->values(),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $groups->values(),
        ]);
    }

    public function latestByStudents(Request $request): JsonResponse
    {
        $raw = $request->query('student_ids', []);
        $ids = is_array($raw)
            ? $raw
            : preg_split('/\s*,\s*/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);

        $latest = $this->cards->latestByStudentIds($ids);
        $data = [];
        foreach ($latest as $studentId => $card) {
            $data[(string) $studentId] = $this->serializeCard($card);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        if ($request->filled('student_id')) {
            $cards = $this->cards->historyForStudent((int) $request->integer('student_id'));
        } elseif ($request->filled('student_ids')) {
            $raw = $request->query('student_ids');
            $ids = is_array($raw)
                ? $raw
                : preg_split('/\s*,\s*/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
            $cards = $this->cards->historyForStudents($ids);
        } else {
            throw ValidationException::withMessages([
                'student_id' => ['Indica student_id o student_ids.'],
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $cards->map(fn (CredentialPrint $card) => $this->serializeCard($card))->values(),
        ]);
    }

    public function enqueue(EnqueueCredentialPrintsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $jobs = $this->cards->enqueue(
            $data['credential_print_ids'],
            $data['side'],
            $request->user(),
            $data['printer_id'] ?? PrintJobService::DEFAULT_PRINTER_ID
        );

        return response()->json([
            'success' => true,
            'data' => $jobs->map(fn (PrintJob $job) => self::serializeJob($job->loadMissing([
                'student.profile:id,first_name,last_name',
                'creator:id,name',
                'canceller:id,name',
                'credentialPrint',
            ])))->values(),
        ]);
    }

    public function discard(DiscardCredentialPrintsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $cards = $this->cards->discard(
            $request->user(),
            $data['credential_print_ids'] ?? null,
            $data['batch_uuid'] ?? null,
            $data['reason'] ?? 'Descartado por el operador'
        );

        return response()->json([
            'success' => true,
            'data' => $cards->map(fn (CredentialPrint $card) => $this->serializeCard($card))->values(),
        ]);
    }

    public function markSide(MarkCredentialPrintSideRequest $request): JsonResponse
    {
        $data = $request->validated();
        $card = $this->cards->markSide(
            (int) $data['credential_print_id'],
            $data['side'],
            $request->user()
        );

        return response()->json([
            'success' => true,
            'data' => $this->serializeCard($card),
        ]);
    }

    /**
     * @param  array{batch_uuid: string, strategy: mixed, cards: \Illuminate\Support\Collection<int, CredentialPrint>}  $batch
     * @return array<string, mixed>
     */
    private function serializeBatch(array $batch): array
    {
        $strategy = $batch['strategy'];
        if ($strategy instanceof PrintBatchStrategy) {
            $strategy = $strategy->value;
        }

        return [
            'batch_uuid' => $batch['batch_uuid'],
            'strategy' => (string) $strategy,
            'cards' => $batch['cards']->map(fn (CredentialPrint $card) => $this->serializeCard($card))->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function serializeCard(CredentialPrint $card): array
    {
        $profile = $card->student?->profile;
        $active = $card->activeJob();

        return [
            'id' => $card->id,
            'uuid' => $card->uuid,
            'student_id' => $card->student_id,
            'student_name' => $profile
                ? trim(($profile->first_name ?? '').' '.($profile->last_name ?? ''))
                : null,
            'academic_year_id' => $card->academic_year_id,
            'card_design_id' => $card->card_design_id,
            'design_key' => $card->cardDesign?->uuid,
            'design_label' => $card->cardDesign?->name,
            'faces_mode' => $card->faces_mode,
            'front_status' => $card->front_status instanceof \BackedEnum
                ? $card->front_status->value
                : (string) $card->front_status,
            'back_status' => $card->back_status instanceof \BackedEnum
                ? $card->back_status->value
                : (string) $card->back_status,
            'strategy' => $card->strategy instanceof \BackedEnum
                ? $card->strategy->value
                : (string) $card->strategy,
            'batch_uuid' => $card->batch_uuid,
            'reason' => $card->reason instanceof \BackedEnum
                ? $card->reason->value
                : (string) $card->reason,
            'created_by' => $card->created_by,
            'created_by_name' => $card->creator?->name,
            'discarded_by' => $card->discarded_by,
            'discarded_by_name' => $card->discarder?->name,
            'discarded_at' => $card->discarded_at?->toIso8601String(),
            'discard_reason' => $card->discard_reason,
            'front_printed_at' => $card->front_printed_at?->toIso8601String(),
            'back_printed_at' => $card->back_printed_at?->toIso8601String(),
            'completed_at' => $card->completed_at?->toIso8601String(),
            'created_at' => $card->created_at?->toIso8601String(),
            'jobs' => $card->printJobs
                ->sortBy('id')
                ->values()
                ->map(fn (PrintJob $job) => self::serializeJob($job))
                ->all(),
            'active_job' => $active ? self::serializeJob($active) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeJob(PrintJob $job): array
    {
        $profile = $job->student?->profile;

        return [
            'id' => $job->id,
            'uuid' => $job->uuid,
            'student_id' => $job->student_id,
            'student_name' => $profile
                ? trim(($profile->first_name ?? '').' '.($profile->last_name ?? ''))
                : null,
            'credential_print_uuid' => $job->credentialPrint?->uuid,
            'printer_id' => $job->printer_id,
            'template_key' => $job->template_key,
            'design_key' => $job->payload_json['design_key'] ?? $job->template_key,
            'design_label' => $job->payload_json['design_label'] ?? null,
            'faces_mode' => $job->payload_json['faces_mode']
                ?? $job->cardDesign?->faces_mode
                ?? 'single',
            'side_mode' => $job->side_mode,
            'status' => $job->status instanceof PrintJobStatus ? $job->status->value : (string) $job->status,
            'payload' => $job->payload_json,
            'attempts' => $job->attempts,
            'last_error' => $job->last_error,
            'claimed_by' => $job->claimed_by,
            'claimed_at' => $job->claimed_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
            'created_by_name' => $job->creator?->name,
            'cancelled_by_name' => $job->canceller?->name,
            'cancelled_at' => $job->cancelled_at?->toIso8601String(),
        ];
    }
}
