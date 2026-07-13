<?php

namespace App\Http\Controllers\School;

use App\Enums\ReEnrollmentValidationStatus;
use App\Enums\ReEnrollmentProcessStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\School\UpdateReEnrollmentApplicationRequest;
use App\Models\School\ReEnrollmentApplication;
use App\Models\School\ReEnrollmentPeriod;
use App\Services\School\ReEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class ReEnrollmentApplicationController extends Controller implements HasMiddleware
{
    public function __construct(
        private readonly ReEnrollmentService $reEnrollmentService
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:manage re-enrollment'),
        ];
    }

    public function index(Request $request, ReEnrollmentPeriod $period): JsonResponse
    {
        $query = $period->applications()
            ->with([
                'student.profile:id,first_name,last_name',
                'enrollment.classGroup.gradeLevel:id,name',
            ]);

        if ($request->filled('status')) {
            $query->where('status', $request->string('status'));
        }

        if ($request->filled('grade')) {
            $grade = $request->string('grade');
            $query->whereHas('enrollment.classGroup.gradeLevel', fn ($q) => $q->where('name', $grade));
        }

        if ($request->filled('group')) {
            $group = $request->string('group');
            $query->whereHas('enrollment.classGroup', fn ($q) => $q->where('name', $group));
        }

        $rows = $query->get()->map(function (ReEnrollmentApplication $app) {
            $enrollment = $app->enrollment;
            $profile = $app->student?->profile;

            return [
                'id' => $app->id,
                'enrollment_id' => $app->enrollment_id,
                'student_id' => $app->student_id,
                'student_name' => trim(($profile?->first_name ?? '') . ' ' . ($profile?->last_name ?? '')),
                'grade' => $enrollment?->classGroup?->gradeLevel?->name,
                'group' => $enrollment?->classGroup?->name,
                'status' => $app->status->value,
                'passed_cycle' => $app->passed_cycle,
                'documents_complete' => $app->documents_complete,
                'guardian_updated' => $app->guardian_updated,
                'phone_updated' => $app->phone_updated,
                'address_updated' => $app->address_updated,
                'photo_updated' => $app->photo_updated,
                'no_debts' => $app->no_debts,
                'comments' => $app->comments,
                'target_class_group_id' => $app->target_class_group_id,
            ];
        });

        return response()->json(['success' => true, 'data' => $rows]);
    }

    public function update(
        UpdateReEnrollmentApplicationRequest $request,
        ReEnrollmentPeriod $period,
        ReEnrollmentApplication $application
    ): JsonResponse {
        if ($period->status === \App\Enums\ReEnrollmentPeriodStatus::FINALIZED) {
            return response()->json(['success' => false, 'message' => 'El periodo está finalizado. Solo consulta.'], 422);
        }

        if ($period->status !== \App\Enums\ReEnrollmentPeriodStatus::OPEN) {
            return response()->json(['success' => false, 'message' => 'Abre el periodo de reinscripción para validar alumnos.'], 422);
        }

        try {
            $this->reEnrollmentService->assertStepAccess($period, ReEnrollmentProcessStep::VALIDATION);
        } catch (\RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        if ($application->re_enrollment_period_id !== $period->id) {
            return response()->json(['success' => false, 'message' => 'Solicitud no pertenece al periodo.'], 404);
        }

        $application->update($request->validated());

        if ($application->isChecklistComplete() && $application->status !== ReEnrollmentValidationStatus::REJECTED) {
            $application->update(['status' => ReEnrollmentValidationStatus::VALIDATED]);

            // Una sola fuente de verdad para promoción: is_approved en la inscripción.
            if ($application->enrollment) {
                $application->enrollment->update([
                    'is_approved' => (bool) $application->passed_cycle,
                ]);
            }
        }

        return response()->json(['success' => true, 'data' => $application->fresh()]);
    }
}
