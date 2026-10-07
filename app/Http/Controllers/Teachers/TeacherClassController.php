<?php

namespace App\Http\Controllers\Teachers;

use App\Http\Controllers\Controller;
use App\Http\Requests\Teachers\SyncTeacherClassesRequest;
use App\Http\Resources\Teachers\TeacherClassOptionResource;
use App\Http\Resources\Teachers\TeacherDetailResource;
use App\Models\Teacher;
use App\Services\Teachers\TeacherClassService;
use App\Services\Teachers\TeacherQueryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class TeacherClassController extends Controller
{
    public function __construct(
        private readonly TeacherClassService $classes,
        private readonly TeacherQueryService $query,
    ) {}

    public function options(Request $request): JsonResponse
    {
        $yearId = $request->query('academic_year_id');
        $classes = $this->classes->availableClasses($yearId !== null ? (int) $yearId : null);

        return response()->json([
            'success' => true,
            'data' => TeacherClassOptionResource::collection($classes)->resolve(),
        ]);
    }

    public function sync(SyncTeacherClassesRequest $request, Teacher $teacher): JsonResponse
    {
        try {
            $teacher = $this->classes->sync($teacher, $request->validated('class_ids') ?? []);
        } catch (RuntimeException $exception) {
            return response()->json(['success' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'data' => new TeacherDetailResource($this->query->detail($teacher)),
        ]);
    }
}
