<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignFirstGradeGroupsRequest;
use App\Services\FirstGradeGroupAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class FirstGradeGroupAssignmentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:edit admission enrollment'),
        ];
    }

    public function __construct(
        private readonly FirstGradeGroupAssignmentService $service
    ) {
    }

    public function assign(AssignFirstGradeGroupsRequest $request): JsonResponse
    {
        $result = $this->service->run(
            academicYearId: (int) $request->input('academic_year_id'),
            scoreSource: $request->filled('score_source') ? (string) $request->input('score_source') : null,
            dryRun: (bool) $request->boolean('dry_run', true),
            overrides: (array) $request->input('overrides', []),
        );

        return response()->json([
            'success' => true,
            'message' => $request->boolean('dry_run', true)
                ? 'Simulación de asignación completada.'
                : 'Asignación aplicada correctamente.',
            'data' => $result,
        ]);
    }
}

