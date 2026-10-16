<?php

namespace App\Http\Controllers\Print;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Print\Concerns\LogsPrintActions;
use App\Http\Requests\Print\StoreCredentialReprintRequest;
use App\Models\CredentialReprintRequest;
use App\Services\Print\CredentialReprintRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CredentialReprintRequestController extends Controller
{
    use LogsPrintActions;

    public function __construct(
        private readonly CredentialReprintRequestService $requests
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'bucket' => ['nullable', 'string', Rule::in(CredentialReprintRequestService::BUCKETS)],
            'reason' => ['nullable', 'string', 'max:32'],
            'search' => ['nullable', 'string', 'max:120'],
            'student_id' => ['nullable', 'integer'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        $paginator = $this->requests->paginate($filters);

        return response()->json([
            'success' => true,
            'data' => $paginator->getCollection()->map(fn (CredentialReprintRequest $r) => $this->serialize($r))->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'counts' => $this->requests->counts($filters),
            ],
        ]);
    }

    public function summary(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->requests->counts(),
        ]);
    }

    public function students(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['required', 'string', 'min:2', 'max:120'],
        ]);

        return response()->json([
            'success' => true,
            'data' => $this->requests->searchStudents($data['search']),
        ]);
    }

    public function store(StoreCredentialReprintRequest $request): JsonResponse
    {
        $data = $request->validated();
        $created = $this->logPrintAction(
            'solicitar reimpresión',
            ['student_id' => (int) $data['student_id'], 'reason' => $data['reason']],
            fn () => $this->requests->create($data, $request->user()),
            fn (CredentialReprintRequest $r) => ['request_uuid' => $r->uuid, 'status' => $r->status->value]
        );

        return response()->json(['success' => true, 'data' => $this->serialize($created)], 201);
    }

    public function approve(Request $request, CredentialReprintRequest $reprintRequest): JsonResponse
    {
        $updated = $this->logPrintAction(
            'aprobar reimpresión',
            ['request_uuid' => $reprintRequest->uuid],
            fn () => $this->requests->approve($reprintRequest, $request->user())
        );

        return response()->json(['success' => true, 'data' => $this->serialize($updated)]);
    }

    public function reject(Request $request, CredentialReprintRequest $reprintRequest): JsonResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $updated = $this->logPrintAction(
            'rechazar reimpresión',
            ['request_uuid' => $reprintRequest->uuid, 'reason' => $data['reason']],
            fn () => $this->requests->reject($reprintRequest, $request->user(), $data['reason'])
        );

        return response()->json(['success' => true, 'data' => $this->serialize($updated)]);
    }

    public function registerPayment(Request $request, CredentialReprintRequest $reprintRequest): JsonResponse
    {
        $data = $request->validate([
            'folio' => ['nullable', 'string', 'max:64'],
            'amount' => ['nullable', 'numeric', 'min:0', 'max:99999'],
        ]);

        $updated = $this->logPrintAction(
            'registrar pago de reimpresión',
            ['request_uuid' => $reprintRequest->uuid] + $data,
            fn () => $this->requests->registerPayment(
                $reprintRequest,
                $request->user(),
                $data['folio'] ?? null,
                isset($data['amount']) ? (float) $data['amount'] : null
            )
        );

        return response()->json(['success' => true, 'data' => $this->serialize($updated)]);
    }

    public function cancel(Request $request, CredentialReprintRequest $reprintRequest): JsonResponse
    {
        $updated = $this->logPrintAction(
            'cancelar reimpresión',
            ['request_uuid' => $reprintRequest->uuid],
            fn () => $this->requests->cancel($reprintRequest, $request->user())
        );

        return response()->json(['success' => true, 'data' => $this->serialize($updated)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(CredentialReprintRequest $r): array
    {
        $profile = $r->student?->profile;
        $name = $profile
            ? trim(implode(' ', array_filter([$profile->first_name, $profile->last_name, $profile->second_last_name])))
            : null;

        return [
            'id' => $r->id,
            'uuid' => $r->uuid,
            'student_id' => $r->student_id,
            'student_name' => $name,
            'class_group_id' => $r->class_group_id,
            'grade_label' => $r->grade_label,
            'group_label' => $r->group_label,
            'reason' => $r->reason->value,
            'reason_label' => $r->reason->label(),
            'notes' => $r->notes,
            'requester_relation' => $r->requester_relation,
            'requester_name' => $r->requester_name,
            'status' => $r->status->value,
            'is_chargeable' => $r->is_chargeable,
            'requested_by_name' => $r->requester?->name,
            'reviewed_by_name' => $r->reviewer?->name,
            'reviewed_at' => $r->reviewed_at?->toIso8601String(),
            'reject_reason' => $r->reject_reason,
            'paid_by_name' => $r->payer?->name,
            'paid_at' => $r->paid_at?->toIso8601String(),
            'payment_folio' => $r->payment_folio,
            'payment_amount' => $r->payment_amount !== null ? (float) $r->payment_amount : null,
            'credential_print_id' => $r->credential_print_id,
            'printed_at' => $r->printed_at?->toIso8601String(),
            'created_at' => $r->created_at?->toIso8601String(),
            'updated_at' => $r->updated_at?->toIso8601String(),
        ];
    }
}
