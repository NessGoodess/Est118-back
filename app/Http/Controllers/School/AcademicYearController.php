<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Http\Requests\School\StoreAcademicYearRequest;
use App\Models\AcademicYear;
use App\Models\Enrollment;
use App\Services\School\AcademicYearService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AcademicYearController extends Controller
{
    public function __construct(
        private readonly AcademicYearService $academicYearService
    ) {}

    public function index(): JsonResponse
    {
        $years = AcademicYear::query()
            ->withCount(['classGroup as class_groups_count'])
            ->orderByDesc('starts_on')
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

    public function update(Request $request, AcademicYear $academicYear): JsonResponse
    {
        unset($request, $academicYear);

        return response()->json([
            'success' => false,
            'message' => 'La edición de ciclos escolares no está habilitada.',
        ], 403);
    }

    public function activate(AcademicYear $academicYear): JsonResponse
    {
        try {
            $year = $this->academicYearService->activate($academicYear);
        } catch (\RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

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
