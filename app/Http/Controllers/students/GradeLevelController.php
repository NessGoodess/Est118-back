<?php

namespace App\Http\Controllers\students;

use App\Http\Controllers\Controller;
use App\Models\GradeLevel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class GradeLevelController extends Controller
{

    /**
     * Get all grades
     */
    public function index()
    {

        //objetivo:agregar el total de grupos por grado
        $gradesData = GradeLevel::leftJoin('class_groups', 'grade_levels.id', '=', 'class_groups.grade_level_id')
            ->leftJoin('enrollments', function ($join) {
                $join->on('class_groups.id', '=', 'enrollments.class_group_id')
                    ->where('enrollments.status', 'active');
            })
            ->select(
                'grade_levels.id as grade_id',
                'grade_levels.name as grade_name',
                'grade_levels.is_active',
                DB::raw('COUNT(DISTINCT enrollments.student_id) as total_students'),
                DB::raw('COUNT(DISTINCT class_groups.id) as total_groups')
            )
            ->groupBy('grade_levels.id', 'grade_levels.name', 'grade_levels.is_active')
            ->get();

        $grandTotal = $gradesData->sum('total_students');

        return response()->json([
            'success' => true,
            'data' => [
                'grades' => $gradesData,
                'totals' => [
                    'total_grades' => $gradesData->count(),
                    'total_students_all_grades' => $grandTotal,
                    'total_groups' => $gradesData->sum('total_groups')
                ]
            ]
        ]);
    }
}
