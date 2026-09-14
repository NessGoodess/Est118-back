<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignBulkGhWorkshopsRequest;
use App\Http\Requests\AssignStudentWorkshopRequest;
use App\Http\Resources\StudentDetailResource;
use App\Models\Student;
use App\Models\Workshop;
use App\Services\AssignBulkGhWorkshopsService;
use App\Services\AssignStudentWorkshopService;
use App\Services\StudentsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use RuntimeException;

class WorkshopController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('auth:sanctum'),
            new Middleware('verified'),
        ];
    }

    public function index(): JsonResponse
    {
        $workshops = Workshop::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name', 'code']);

        return response()->json([
            'success' => true,
            'data' => $workshops,
        ]);
    }

    public function assignToStudent(
        AssignStudentWorkshopRequest $request,
        Student $student,
        AssignStudentWorkshopService $assigner,
        StudentsService $students,
    ): JsonResponse {
        $user = $request->user();
        if (
            ! $user
            || (
                ! $user->can('edit students')
                && ! $user->can('edit admission enrollment')
                && ! $user->can('edit student workshops')
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para asignar talleres.',
            ], 403);
        }

        try {
            $result = $assigner->assign(
                student: $student,
                workshopId: (int) $request->integer('workshop_id'),
                notes: $request->input('notes'),
                force: $request->boolean('force', false),
                academicYearId: $request->filled('academic_year_id')
                    ? (int) $request->integer('academic_year_id')
                    : null,
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        $detail = $students->findForDetail($student->id);

        return response()->json([
            'success' => true,
            'message' => 'Taller asignado.',
            'warnings' => $result['warnings'],
            'data' => (new StudentDetailResource($detail))->resolve(),
        ]);
    }

    public function bulkGh(
        AssignBulkGhWorkshopsRequest $request,
        AssignBulkGhWorkshopsService $service,
    ): JsonResponse {
        $user = $request->user();
        if (
            ! $user
            || (
                ! $user->can('edit student workshops')
                && ! $user->can('edit admission enrollment')
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para asignar talleres G/H.',
            ], 403);
        }

        try {
            $result = $service->run(
                academicYearId: (int) $request->integer('academic_year_id'),
                dryRun: $request->boolean('dry_run', true),
                force: $request->boolean('force', false),
            );
        } catch (RuntimeException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $request->boolean('dry_run', true)
                ? 'Simulación G/H completada.'
                : 'Asignación G/H aplicada.',
            'data' => $result,
        ]);
    }
}
