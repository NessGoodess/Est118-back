<?php

namespace App\Http\Controllers\Admission;

use App\Enums\EnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Models\AcademicYear;
use App\Models\ClassGroup;
use App\Models\Enrollment;
use App\Models\GradeLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class FirstGradeGroupsController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('auth:sanctum'),
            new Middleware('verified'),
            new Middleware('permission:view admission enrollment|edit admission enrollment'),
        ];
    }

    public function index(AcademicYear $academicYear): JsonResponse
    {
        $firstGrade = GradeLevel::query()->where('name', '1°')->first();
        if (! $firstGrade) {
            return response()->json([
                'success' => true,
                'data' => [],
            ]);
        }

        $groups = ClassGroup::query()
            ->where('academic_year_id', $academicYear->id)
            ->where('grade_level_id', $firstGrade->id)
            ->orderBy('name')
            ->get(['id', 'name']);

        $counts = Enrollment::query()
            ->where('academic_year_id', $academicYear->id)
            ->where('status', EnrollmentStatus::Active->value)
            ->whereIn('class_group_id', $groups->pluck('id'))
            ->selectRaw('class_group_id, COUNT(*) as total')
            ->groupBy('class_group_id')
            ->pluck('total', 'class_group_id');

        $data = $groups->map(fn (ClassGroup $g) => [
            'id' => $g->id,
            'name' => $g->name,
            'label' => "1° {$g->name}",
            'active_count' => (int) ($counts[$g->id] ?? 0),
        ])->values();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }
}
