<?php

namespace App\Http\Controllers\Teachers;

use App\Http\Controllers\Controller;
use App\Http\Requests\People\UpdatePersonStatusRequest;
use App\Http\Requests\Teachers\StoreTeacherRequest;
use App\Http\Requests\Teachers\UpdateTeacherRequest;
use App\Http\Resources\Teachers\TeacherDetailResource;
use App\Http\Resources\Teachers\TeacherListItemResource;
use App\Models\Teacher;
use App\Services\Teachers\TeacherClassService;
use App\Services\Teachers\TeacherQueryService;
use App\Services\Teachers\TeacherStatusService;
use App\Services\Teachers\TeacherWriteService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TeacherController extends Controller
{
    public function __construct(
        private readonly TeacherQueryService $query,
        private readonly TeacherWriteService $write,
        private readonly TeacherStatusService $status,
        private readonly TeacherClassService $classes,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $teachers = $this->query->list($request->query('status'));

        return response()->json([
            'success' => true,
            'data' => TeacherListItemResource::collection($teachers)->resolve(),
        ]);
    }

    public function store(StoreTeacherRequest $request): JsonResponse
    {
        try {
            $teacher = $this->write->create($request->validated());
            $classIds = $request->validated('class_ids') ?? [];
            if ($classIds !== []) {
                $teacher = $this->classes->sync($teacher, $classIds);
            }
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new TeacherDetailResource($this->query->detail($teacher)),
        ], 201);
    }

    public function show(Teacher $teacher): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => new TeacherDetailResource($this->query->detail($teacher)),
        ]);
    }

    public function update(UpdateTeacherRequest $request, Teacher $teacher): JsonResponse
    {
        try {
            $teacher = $this->write->update($teacher, $request->validated());
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new TeacherDetailResource($this->query->detail($teacher)),
        ]);
    }

    public function status(UpdatePersonStatusRequest $request, Teacher $teacher): JsonResponse
    {
        try {
            $teacher = $this->status->setStatus($teacher, $request->validated('status'));
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new TeacherDetailResource($this->query->detail($teacher)),
        ]);
    }
}
