<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Http\Requests\School\StoreAcademicYearRequest;
use App\Http\Requests\School\UpdateAcademicYearRequest;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Services\School\AcademicYearService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class AcademicYearController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly AcademicYearService $academicYearService
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:manage re-enrollment'),
        ];
    }

    public function index(): JsonResponse
    {
        $years = AcademicYear::query()
            ->withCount(['classGroup as class_groups_count'])
            ->orderByDesc('year_start')
            ->get();

        return response()->json(['success' => true, 'data' => $years]);
    }

    public function store(StoreAcademicYearRequest $request): JsonResponse
    {
        $year = $this->academicYearService->create($request->validated());

        return response()->json([
            'success' => true,
            'message' => 'Ciclo escolar creado correctamente.',
            'data' => $year,
        ], 201);
    }

    public function update(UpdateAcademicYearRequest $request, AcademicYear $academicYear): JsonResponse
    {
        $academicYear->update($request->validated());

        return response()->json(['success' => true, 'data' => $academicYear->fresh()]);
    }

    public function activate(AcademicYear $academicYear): JsonResponse
    {
        $year = $this->academicYearService->activate($academicYear);

        return response()->json([
            'success' => true,
            'message' => 'Ciclo escolar activado.',
            'data' => $year,
        ]);
    }

    public function generateGroups(AcademicYear $academicYear): JsonResponse
    {
        $created = $this->academicYearService->generateClassGroups($academicYear);

        return response()->json([
            'success' => true,
            'message' => "Se crearon {$created} grupos.",
            'data' => ['created' => $created],
        ]);
    }

    public function destroy(AcademicYear $academicYear): JsonResponse
    {
        if ($academicYear->is_active) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes eliminar el ciclo escolar activo.',
            ], 422);
        }

        $hasEnrollments = Enrollment::query()
            ->where('academic_year_id', $academicYear->id)
            ->exists();

        if ($hasEnrollments) {
            return response()->json([
                'success' => false,
                'message' => 'No puedes eliminar un ciclo con inscripciones registradas.',
            ], 422);
        }

        $academicYear->delete();

        return response()->json([
            'success' => true,
            'message' => 'Ciclo escolar eliminado.',
        ]);
    }
}
