<?php

namespace App\Http\Controllers\Staff;

use App\Http\Controllers\Controller;
use App\Http\Requests\People\UpdatePersonStatusRequest;
use App\Http\Requests\Staff\StoreStaffRequest;
use App\Http\Requests\Staff\UpdateStaffRequest;
use App\Http\Resources\Staff\StaffDetailResource;
use App\Http\Resources\Staff\StaffListItemResource;
use App\Models\Staff;
use App\Services\Staff\StaffQueryService;
use App\Services\Staff\StaffStatusService;
use App\Services\Staff\StaffWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class StaffController extends Controller
{
    public function __construct(
        private readonly StaffQueryService $query,
        private readonly StaffWriteService $write,
        private readonly StaffStatusService $status,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $rows = $this->query->list($request->query('status'));

        return response()->json([
            'success' => true,
            'data' => StaffListItemResource::collection($rows)->resolve(),
        ]);
    }

    public function store(StoreStaffRequest $request): JsonResponse
    {
        try {
            $staff = $this->write->create($request->validated());
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new StaffDetailResource($this->query->detail($staff)),
        ], 201);
    }

    public function show(Staff $staff): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new StaffDetailResource($this->query->detail($staff)),
        ]);
    }

    public function update(UpdateStaffRequest $request, Staff $staff): JsonResponse
    {
        try {
            $staff = $this->write->update($staff, $request->validated());
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new StaffDetailResource($this->query->detail($staff)),
        ]);
    }

    public function status(UpdatePersonStatusRequest $request, Staff $staff): JsonResponse
    {
        try {
            $staff = $this->status->setStatus($staff, $request->validated('status'));
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new StaffDetailResource($this->query->detail($staff)),
        ]);
    }
}
