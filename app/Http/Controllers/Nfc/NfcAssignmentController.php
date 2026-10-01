<?php

namespace App\Http\Controllers\Nfc;

use App\Http\Controllers\Controller;
use App\Http\Requests\Nfc\StoreNfcAssignmentRequest;
use App\Models\NfcAssignments;
use App\Models\Student;
use App\Services\Nfc\NfcAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NfcAssignmentController extends Controller
{
    public function __construct(
        private readonly NfcAssignmentService $assignments
    ) {}

    public function store(StoreNfcAssignmentRequest $request): JsonResponse
    {
        $student = Student::query()->findOrFail($request->validated('student_id'));
        $result = $this->assignments->enqueueResult(
            $student,
            $request->validated('action'),
            $request->user()
        );

        $payload = $this->assignments->payload($result['job']);

        if (! $result['created']) {
            return response()->json([
                'success' => false,
                'message' => 'Ya hay una asignación NFC en curso.',
                'data' => $payload,
            ], 409);
        }

        return response()->json([
            'success' => true,
            'data' => $payload,
        ], 201);
    }

    public function show(NfcAssignments $nfcAssignment): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->assignments->payload($nfcAssignment),
        ]);
    }

    public function active(): JsonResponse
    {
        $job = $this->assignments->active();

        return response()->json([
            'success' => true,
            'data' => $job ? $this->assignments->payload($job) : null,
        ]);
    }

    public function latestByStudents(Request $request): JsonResponse
    {
        $ids = $request->query('student_ids', []);
        if (is_string($ids)) {
            $ids = array_filter(explode(',', $ids));
        }
        $ids = array_values(array_unique(array_map('intval', (array) $ids)));

        return response()->json([
            'success' => true,
            'data' => $this->assignments->latestByStudents($ids),
        ]);
    }

    public function cancel(NfcAssignments $nfcAssignment): JsonResponse
    {
        $job = $this->assignments->cancel($nfcAssignment);

        return response()->json([
            'success' => true,
            'data' => $this->assignments->payload($job),
        ]);
    }
}
