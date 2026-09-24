<?php

namespace App\Http\Controllers\Print;

use App\Enums\ServiceAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Print\StorePrintJobsRequest;
use App\Models\PrintJob;
use App\Models\User;
use App\Services\Print\PrintJobService;
use App\Services\Print\StudentCardRenderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class PrintJobController extends Controller
{
    public function __construct(
        private readonly PrintJobService $printJobs,
        private readonly StudentCardRenderService $renderer
    ) {}

    public function preview(Request $request): Response|JsonResponse
    {
        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'template_key' => ['sometimes', 'string', 'max:64'],
        ]);

        try {
            $png = $this->renderer->previewPng(
                (int) $data['student_id'],
                $data['template_key'] ?? 'student-card-v1'
            );
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response($png, 200, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'no-store, private',
        ]);
    }
    public function index(Request $request): JsonResponse
    {
        $filters = [
            'status' => $request->query('status'),
            'active_only' => $request->boolean('active_only'),
        ];

        if ($request->filled('student_ids')) {
            $raw = $request->query('student_ids');
            $filters['student_ids'] = is_array($raw)
                ? $raw
                : preg_split('/\s*,\s*/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);
        }

        $paginator = $this->printJobs->listFiltered(
            (int) $request->integer('per_page', 20),
            $filters
        );

        return response()->json([
            'success' => true,
            'data' => collect($paginator->items())->map(fn (PrintJob $j) => $this->serialize($j))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    public function latestByStudents(Request $request): JsonResponse
    {
        $raw = $request->query('student_ids', []);
        $ids = is_array($raw)
            ? $raw
            : preg_split('/\s*,\s*/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY);

        $latest = $this->printJobs->latestByStudentIds($ids);
        $data = [];
        foreach ($latest as $studentId => $job) {
            $data[(string) $studentId] = $this->serialize($job);
        }

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    public function store(StorePrintJobsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $jobs = $this->printJobs->createJobs(
            $data['student_ids'],
            $request->user(),
            $data['printer_id'] ?? PrintJobService::DEFAULT_PRINTER_ID,
            $data['template_key'] ?? 'student-card-v1',
            $data['side_mode'] ?? 'front'
        );

        return response()->json([
            'success' => true,
            'data' => collect($jobs)->map(fn (PrintJob $j) => $this->serialize($j))->values(),
        ], 201);
    }

    public function resolve(StorePrintJobsRequest $request): JsonResponse
    {
        $data = $request->validated();
        $rows = $this->printJobs->resolveDesigns(
            $data['student_ids'],
            $data['template_key'] ?? 'student-card-v1'
        );

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    public function queue(Request $request): JsonResponse
    {
        $printerId = (string) $request->query('printer_id', PrintJobService::DEFAULT_PRINTER_ID);
        $snapshot = $this->printJobs->queueSnapshot($printerId);

        return response()->json([
            'success' => true,
            'data' => [
                'printer_id' => $snapshot['printer_id'],
                'paused' => $snapshot['paused'],
                'pause_reason' => $snapshot['pause_reason'],
                'agent' => $snapshot['agent'],
                'jobs' => collect($snapshot['jobs'])->map(fn (PrintJob $j) => $this->serialize($j))->values(),
            ],
        ]);
    }

    public function cancelQueue(Request $request): JsonResponse
    {
        $printerId = (string) $request->input('printer_id', PrintJobService::DEFAULT_PRINTER_ID);
        $jobs = $this->printJobs->cancelQueue($printerId);

        return response()->json([
            'success' => true,
            'data' => collect($jobs)->map(fn (PrintJob $j) => $this->serialize($j))->values(),
        ]);
    }

    public function resume(Request $request): JsonResponse
    {
        $printerId = (string) $request->input('printer_id', PrintJobService::DEFAULT_PRINTER_ID);
        $jobs = $this->printJobs->resumeQueue($printerId);

        return response()->json([
            'success' => true,
            'data' => collect($jobs)->map(fn (PrintJob $j) => $this->serialize($j))->values(),
        ]);
    }

    public function show(PrintJob $printJob): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->serialize($printJob->load('student.profile')),
        ]);
    }

    public function cancel(PrintJob $printJob): JsonResponse
    {
        $job = $this->printJobs->cancel($printJob);

        return response()->json([
            'success' => true,
            'data' => $this->serialize($job),
        ]);
    }

    public function agentStatus(Request $request): JsonResponse
    {
        $printerId = (string) $request->query('printer_id', PrintJobService::DEFAULT_PRINTER_ID);

        return response()->json([
            'success' => true,
            'data' => $this->printJobs->heartbeatStatus($printerId),
        ]);
    }

    /**
     * Issue a ZC300 print-agent service token (admin + password confirmation).
     * Revokes previous tokens with the same name on the service user.
     */
    public function issueAgentToken(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
        ]);

        $serviceUser = User::query()
            ->where('email', 'print-agent@est118.edu.mx')
            ->first();

        if (! $serviceUser) {
            throw ValidationException::withMessages([
                'token' => 'Usuario de servicio print-agent no encontrado. Ejecuta el seeder de service users.',
            ]);
        }

        $tokenName = 'zc300-print-agent';
        $ability = ServiceAbility::PRINT_AGENT->value;
        $revoked = $serviceUser->tokens()->where('name', $tokenName)->delete();
        $token = $serviceUser->createToken($tokenName, [$ability]);

        return response()->json([
            'success' => true,
            'data' => [
                'token' => $token->plainTextToken,
                'token_name' => $tokenName,
                'ability' => $ability,
                'revoked_previous' => (int) $revoked,
                'hint' => 'Copia el token ahora. No se volverá a mostrar.',
            ],
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(PrintJob $job): array
    {
        $profile = $job->student?->profile;

        return [
            'id' => $job->id,
            'uuid' => $job->uuid,
            'student_id' => $job->student_id,
            'student_name' => $profile
                ? trim(($profile->first_name ?? '').' '.($profile->last_name ?? ''))
                : null,
            'printer_id' => $job->printer_id,
            'template_key' => $job->template_key,
            'design_key' => $job->payload_json['design_key'] ?? $job->template_key,
            'design_label' => $job->payload_json['design_label'] ?? null,
            'faces_mode' => $job->payload_json['faces_mode']
                ?? $job->cardDesign?->faces_mode
                ?? 'single',
            'side_mode' => $job->side_mode,
            'status' => $job->status instanceof \BackedEnum ? $job->status->value : (string) $job->status,
            'payload' => $job->payload_json,
            'attempts' => $job->attempts,
            'last_error' => $job->last_error,
            'claimed_by' => $job->claimed_by,
            'claimed_at' => $job->claimed_at?->toIso8601String(),
            'completed_at' => $job->completed_at?->toIso8601String(),
            'created_at' => $job->created_at?->toIso8601String(),
        ];
    }
}
