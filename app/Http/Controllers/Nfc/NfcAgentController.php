<?php

namespace App\Http\Controllers\Nfc;

use App\Http\Controllers\Controller;
use App\Models\NfcAssignments;
use App\Services\Nfc\NfcAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class NfcAgentController extends Controller
{
    public function __construct(
        private readonly NfcAssignmentService $assignments
    ) {}

    public function next(Request $request): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'string', 'max:128'],
        ]);

        $job = $this->assignments->claimNext($data['agent_id']);

        return response()->json([
            'success' => true,
            'data' => $job ? $this->assignments->agentPayload($job) : null,
        ]);
    }

    public function progress(Request $request, NfcAssignments $nfcAssignment): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'string', 'max:128'],
            'status' => ['required', 'string', Rule::in(NfcAssignments::PROGRESS_STATUSES)],
            'nfc_uid' => ['nullable', 'string', 'max:64'],
            'message' => ['nullable', 'string', 'max:500'],
            'reader_name' => ['nullable', 'string', 'max:128'],
        ]);

        $job = $this->assignments->progress(
            $nfcAssignment,
            $data['agent_id'],
            $data['status'],
            $data['nfc_uid'] ?? null,
            $data['message'] ?? null,
            $data['reader_name'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => $this->assignments->agentPayload($job),
        ]);
    }

    public function complete(Request $request, NfcAssignments $nfcAssignment): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'string', 'max:128'],
            'nfc_uid' => ['nullable', 'string', 'max:64'],
            'read_back' => ['nullable', 'string', 'max:160'],
        ]);

        $job = $this->assignments->complete(
            $nfcAssignment,
            $data['agent_id'],
            $data['nfc_uid'] ?? null,
            $data['read_back'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => $this->assignments->agentPayload($job),
        ]);
    }

    public function fail(Request $request, NfcAssignments $nfcAssignment): JsonResponse
    {
        $data = $request->validate([
            'agent_id' => ['required', 'string', 'max:128'],
            'failure_code' => ['required', 'string', Rule::in(NfcAssignments::FAILURE_CODES)],
            'message' => ['required', 'string', 'max:500'],
            'nfc_uid' => ['nullable', 'string', 'max:64'],
            'read_back' => ['nullable', 'string', 'max:160'],
        ]);

        $job = $this->assignments->fail(
            $nfcAssignment,
            $data['agent_id'],
            $data['failure_code'],
            $data['message'],
            $data['nfc_uid'] ?? null,
            $data['read_back'] ?? null
        );

        return response()->json([
            'success' => true,
            'data' => $this->assignments->agentPayload($job),
        ]);
    }
}
