<?php

namespace App\Http\Controllers;

use App\Enums\EnrollmentStatus;
use App\Http\Requests\UpdateEnrollmentPromotionDecisionRequest;
use App\Models\Enrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EnrollmentPromotionController extends Controller
{
    /**
     * List active enrollments that still need final decision (approved/reproved).
     */
    public function pendingDecisions(Request $request): JsonResponse
    {
        $academicYearId = $request->integer('academic_year_id');

        $query = Enrollment::query()
            ->with([
                'student.profile:id,first_name,last_name',
                'classGroup.gradeLevel:id,name',
            ])
            ->where('status', EnrollmentStatus::Active->value)
            ->whereNull('is_approved');

        if ($academicYearId > 0) {
            $query->where('academic_year_id', $academicYearId);
        }

        $rows = $query->get()->map(function (Enrollment $enrollment) {
            return [
                'enrollment_id' => $enrollment->id,
                'student_id' => $enrollment->student_id,
                'student_name' => trim(
                    ($enrollment->student?->profile?->first_name ?? '') . ' ' .
                    ($enrollment->student?->profile?->last_name ?? '')
                ),
                'academic_year_id' => $enrollment->academic_year_id,
                'grade' => $enrollment->classGroup?->gradeLevel?->name,
                'group' => $enrollment->classGroup?->name,
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    /**
     * Save final yearly decision for one enrollment.
     */
    public function updateDecision(
        UpdateEnrollmentPromotionDecisionRequest $request,
        Enrollment $enrollment
    ): JsonResponse {
        if ($enrollment->status !== EnrollmentStatus::Active) {
            return response()->json([
                'success' => false,
                'message' => 'Solo se puede capturar decisión en inscripciones activas.',
            ], 422);
        }

        $enrollment->update([
            'is_approved' => (bool) $request->boolean('is_approved'),
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Decisión de promoción guardada correctamente.',
            'data' => [
                'enrollment_id' => $enrollment->id,
                'is_approved' => $enrollment->is_approved,
            ],
        ]);
    }
}
