<?php

namespace App\Http\Controllers;

use App\Http\Requests\AssignFirstGradeWorkshopsRequest;
use App\Services\FirstGradeWorkshopAssignmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use RuntimeException;

class FirstGradeWorkshopAssignmentController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('permission:edit admission enrollment'),
        ];
    }

    public function __construct(
        private readonly FirstGradeWorkshopAssignmentService $service
    ) {}

    public function assign(AssignFirstGradeWorkshopsRequest $request): JsonResponse
    {
        try {
            $result = $this->service->run(
                academicYearId: (int) $request->input('academic_year_id'),
                scoreSource: $request->filled('score_source') ? (string) $request->input('score_source') : null,
                dryRun: (bool) $request->boolean('dry_run', true),
                overrides: (array) $request->input('overrides', []),
                warnGhMismatch: $request->boolean('warn_gh_mismatch', true),
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
                ? 'Simulación de asignación de talleres completada.'
                : 'Asignación de talleres aplicada.',
            'data' => $result,
        ]);
    }
}
