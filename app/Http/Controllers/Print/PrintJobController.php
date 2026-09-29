<?php

namespace App\Http\Controllers\Print;

use App\Enums\PrintBatchStrategy;
use App\Enums\ServiceAbility;
use App\Http\Controllers\Controller;
use App\Http\Requests\Print\StorePrintJobsRequest;
use App\Models\PrintJob;
use App\Models\User;
use App\Services\Print\CredentialPrintService;
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
        private readonly StudentCardRenderService $renderer,
        private readonly CredentialPrintService $credentialPrints
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

        $paginator->getCollection()->load([
            'student.profile:id,first_name,last_name',
            'creator:id,name',
            'canceller:id,name',
            'credentialPrint',
        ]);

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
        if (($data['side_mode'] ?? 'front') === 'back') {
            throw ValidationException::withMessages([
                'side_mode' => ['Los reversos se encolan desde /credential-prints/enqueue.'],
            ]);
        }

        $batch = $this->credentialPrints->createBatch(
            $data['student_ids'],
            $request->user(),
            $data['template_key'] ?? 'student-card-v1',
            PrintBatchStrategy::FrontsThenBacks,
            $data['printer_id'] ?? PrintJobService::DEFAULT_PRINTER_ID
        );

        $jobs = $batch['cards']
            ->flatMap(fn ($card) => $card->printJobs)
            ->values();

        return response()->json([
            'success' => true,
            'data' => $jobs->map(fn (PrintJob $j) => $this->serialize($j))->values(),
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
        $jobs = $this->printJobs->cancelQueue($printerId, $request->user());

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
        $job = $this->printJobs->cancel($printJob, $request->user());

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

    private function serialize(PrintJob $job): array
    {
        $job->loadMissing([
            'student.profile:id,first_name,last_name',
            'creator:id,name',
            'canceller:id,name',
            'credentialPrint',
        ]);

        return CredentialPrintController::serializeJob($job);
    }
}
