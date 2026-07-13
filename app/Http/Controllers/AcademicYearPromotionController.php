<?php

namespace App\Http\Controllers;

use App\Http\Requests\PromoteAcademicYearRequest;
use App\Models\AcademicYear;
use App\Services\EnrollmentPromotionService;
use Illuminate\Http\JsonResponse;

class AcademicYearPromotionController extends Controller
{
    public function __construct(
        private readonly EnrollmentPromotionService $promotionService
    ) {
    }

    /**
     * List academic years for select inputs.
     */
    public function index(): JsonResponse
    {
        $years = AcademicYear::query()
            ->orderByDesc('year_start')
            ->get(['id', 'year_start', 'year_end', 'description', 'is_active']);

        return response()->json([
            'success' => true,
            'data' => $years,
        ]);
    }

    /**
     * Run promotion (dry-run or real).
     */
    public function promote(PromoteAcademicYearRequest $request): JsonResponse
    {
        $from = (int) $request->input('from_academic_year_id');
        $to = (int) $request->input('to_academic_year_id');
        $dryRun = (bool) $request->boolean('dry_run', false);

        $summary = $this->promotionService->promote($from, $to, $dryRun);

        return response()->json([
            'success' => true,
            'message' => $dryRun ? 'Simulación completada.' : 'Promoción completada.',
            'data' => $summary,
        ]);
    }
}

