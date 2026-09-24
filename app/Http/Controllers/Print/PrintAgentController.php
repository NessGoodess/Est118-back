<?php

namespace App\Http\Controllers\Print;

use App\Enums\PrintJobStatus;
use App\Http\Controllers\Controller;
use App\Models\PrintJob;
use App\Services\Print\CardTemplateService;
use App\Services\Print\PrintJobService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PrintAgentController extends Controller
{
    public function __construct(
        private readonly PrintJobService $printJobs,
        private readonly CardTemplateService $templates
    ) {}

    public function heartbeat(Request $request): JsonResponse
    {
        $data = $request->validate([
            'printer_id' => ['required', 'string', 'max:64'],
            'agent_id' => ['required', 'string', 'max:128'],
            'meta' => ['sometimes', 'array'],
        ]);

        $payload = $this->printJobs->recordHeartbeat(
            $data['printer_id'],
            $data['agent_id'],
            $data['meta'] ?? []
        );

        return response()->json([
            'success' => true,
            'data' => $payload,
        ]);
    }

    public function next(Request $request): JsonResponse
    {
        $data = $request->validate([
            'printer_id' => ['required', 'string', 'max:64'],
            'agent_id' => ['required', 'string', 'max:128'],
        ]);

        $job = $this->printJobs->claimNext($data['printer_id'], $data['agent_id']);
        if (! $job) {
            return response()->json([
                'success' => true,
                'data' => null,
                'queue_paused' => $this->printJobs->isQueuePaused($data['printer_id']),
                'pause_reason' => $this->printJobs->pauseReason($data['printer_id']),
            ]);
        }

        return response()->json([
            'success' => true,
            'data' => $this->agentPayload($job),
        ]);
    }

    public function claim(Request $request, PrintJob $printJob): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'string', 'max:128'],
        ]);

        if ($printJob->status !== PrintJobStatus::Ready) {
            return response()->json([
                'success' => false,
                'message' => 'Job is not ready to claim.',
            ], 409);
        }

        $printJob->update([
            'status' => PrintJobStatus::Claimed,
            'claimed_by' => $data['agent_id'],
            'claimed_at' => now(),
            'attempts' => $printJob->attempts + 1,
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->agentPayload($printJob->fresh()),
        ]);
    }

    public function complete(Request $request, PrintJob $printJob): JsonResponse
    {
        $request->validate([
            'agent_id' => ['required', 'string', 'max:128'],
        ]);

        if ($printJob->claimed_by && $printJob->claimed_by !== $request->input('agent_id')) {
            return response()->json(['success' => false, 'message' => 'Job claimed by another agent.'], 409);
        }

        if ($printJob->status === PrintJobStatus::Claimed) {
            $this->printJobs->markPrinting($printJob);
            $printJob = $printJob->fresh();
        }

        $job = $this->printJobs->markCompleted($printJob);

        return response()->json([
            'success' => true,
            'data' => $this->agentPayload($job),
        ]);
    }

    public function fail(Request $request, PrintJob $printJob): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'string', 'max:128'],
            'error' => ['required', 'string', 'max:2000'],
        ]);

        if ($printJob->claimed_by && $printJob->claimed_by !== $data['agent_id']) {
            return response()->json(['success' => false, 'message' => 'Job claimed by another agent.'], 409);
        }

        $job = $this->printJobs->markFailed($printJob, $data['error']);

        return response()->json([
            'success' => true,
            'data' => $this->agentPayload($job),
        ]);
    }

    public function frontAsset(PrintJob $printJob): StreamedResponse|JsonResponse
    {
        return $this->streamSide($printJob, 'front');
    }

    public function backAsset(PrintJob $printJob): StreamedResponse|JsonResponse
    {
        return $this->streamSide($printJob, 'back');
    }

    private function streamSide(PrintJob $printJob, string $side): StreamedResponse|JsonResponse
    {
        $path = $side === 'back' ? $printJob->back_path : $printJob->front_path;
        if (! $path || ! Storage::disk('private')->exists($path)) {
            return response()->json([
                'success' => false,
                'message' => "Asset [{$side}] not found.",
            ], 404);
        }

        return Storage::disk('private')->response($path, basename($path), [
            'Content-Type' => 'image/png',
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function agentPayload(PrintJob $job): array
    {
        $orientation = $job->payload_json['orientation'] ?? null;
        if (! in_array($orientation, ['landscape', 'portrait'], true)) {
            $orientation = $this->templates->orientation((string) $job->template_key);
        }

        return [
            'job_id' => $job->uuid,
            'printer_id' => $job->printer_id,
            'template_key' => $job->template_key,
            'side_mode' => $job->side_mode,
            'status' => $job->status->value,
            'orientation' => $orientation,
            'has_back' => (bool) $job->back_path,
            'student' => [
                'id' => $job->student_id,
                'name' => $job->payload_json['full_name'] ?? null,
            ],
            // Agent downloads these with the same Bearer service token.
            'asset_paths' => [
                'front' => '/api/agent/print-jobs/'.$job->uuid.'/assets/front',
                'back' => $job->back_path ? '/api/agent/print-jobs/'.$job->uuid.'/assets/back' : null,
            ],
        ];
    }

    public function queueStats(Request $request): JsonResponse
    {
        $data = $request->validate([
            'printer_id' => ['required', 'string', 'max:64'],
        ]);

        $printerId = $data['printer_id'];
        $base = PrintJob::query()->where('printer_id', $printerId);

        return response()->json([
            'success' => true,
            'data' => [
                'pending' => (clone $base)->where('status', PrintJobStatus::Pending)->count(),
                'ready' => (clone $base)->where('status', PrintJobStatus::Ready)->count(),
                'active' => (clone $base)->whereIn('status', [
                    PrintJobStatus::Claimed,
                    PrintJobStatus::Printing,
                ])->count(),
                'completed' => (clone $base)->where('status', PrintJobStatus::Completed)->count(),
                'failed' => (clone $base)->where('status', PrintJobStatus::Failed)->count(),
            ],
        ]);
    }
}
