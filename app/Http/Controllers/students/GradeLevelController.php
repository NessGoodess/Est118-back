<?php

namespace App\Http\Controllers\students;

use App\Http\Controllers\Controller;
use App\Models\GradeLevel;
use App\Services\StudentsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GradeLevelController extends Controller
{
    public function __construct(
        protected StudentsService $studentsService,
    ) {}

    /**
     * Grade cards for one academic year (defaults to the active cycle).
     * Student totals include every enrollment status in that cycle.
     */
    public function index(Request $request)
    {
        $validated = $request->validate([
            'academic_year_id' => ['nullable', 'integer', 'exists:academic_years,id'],
        ]);

        $yearId = $this->studentsService->resolveYearId(
            isset($validated['academic_year_id']) ? (int) $validated['academic_year_id'] : null
        );

        $gradesData = GradeLevel::query()
            ->leftJoin('class_groups', function ($join) use ($yearId) {
                $join->on('grade_levels.id', '=', 'class_groups.grade_level_id');
                if ($yearId !== null) {
                    $join->where('class_groups.academic_year_id', $yearId);
                }
            })
            ->leftJoin('enrollments', 'class_groups.id', '=', 'enrollments.class_group_id')
            ->select(
                'grade_levels.id as grade_id',
                'grade_levels.name as grade_name',
                'grade_levels.is_active',
                DB::raw('COUNT(DISTINCT enrollments.student_id) as total_students'),
                DB::raw('COUNT(DISTINCT class_groups.id) as total_groups')
            )
            ->groupBy('grade_levels.id', 'grade_levels.name', 'grade_levels.is_active')
            ->orderBy('grade_levels.id')
            ->get();

        $grandTotal = $gradesData->sum('total_students');

        return response()->json([
            'success' => true,
            'data' => [
                'academic_year_id' => $yearId,
                'grades' => $gradesData,
                'totals' => [
                    'total_grades' => $gradesData->count(),
                    'total_students_all_grades' => $grandTotal,
                    'total_groups' => $gradesData->sum('total_groups'),
                ],
            ],
        ]);
    }
}
