<?php

namespace App\Http\Controllers\School;

use App\Enums\ReEnrollmentValidationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\School\BulkDecideReEnrollmentApplicationsRequest;
use App\Http\Requests\School\BulkValidateReEnrollmentApplicationsRequest;
use App\Http\Requests\School\UpdateReEnrollmentApplicationRequest;
use App\Models\School\ReEnrollmentApplication;
use App\Models\School\ReEnrollmentPeriod;
use App\Services\School\ReEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use RuntimeException;

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
        if ($application->re_enrollment_period_id !== $period->id) {
            return response()->json(['success' => false, 'message' => 'Solicitud no pertenece al periodo.'], 404);
        }

        $payload = $request->validated();
        $status = $payload['status'] ?? null;
        $statusValue = $status instanceof ReEnrollmentValidationStatus ? $status->value : $status;
        $shouldReject = $statusValue === ReEnrollmentValidationStatus::REJECTED->value;

        try {
            $this->reEnrollmentService->assertCanValidate($period);

            if ($shouldReject) {
                $this->reEnrollmentService->rejectApplication($application->loadMissing('enrollment'));
            } else {
                unset($payload['passed_cycle']);
                $application->update($payload);
                $this->reEnrollmentService->applyChecklistOutcome($application->fresh());
            }
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $application->fresh()]);
    }

    public function bulkValidate(
        BulkValidateReEnrollmentApplicationsRequest $request,
        ReEnrollmentPeriod $period
    ): JsonResponse {
        try {
            $result = $this->reEnrollmentService->bulkValidateChecklist(
                $period,
                array_values(array_unique($request->validated('scopes'))),
                $request->validated('grade'),
                $request->validated('group')
            );
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $result['updated'] === 0
                ? 'No hay alumnos pendientes de validar con ese filtro.'
                : "Se validaron {$result['updated']} alumnos.",
            'data' => $result,
        ]);
    }

    public function bulkDecide(
        BulkDecideReEnrollmentApplicationsRequest $request,
        ReEnrollmentPeriod $period
    ): JsonResponse {
        $reject = (bool) $request->boolean('reject');
        $approved = $request->exists('is_approved') ? $request->boolean('is_approved') : null;

        try {
            $result = $this->reEnrollmentService->bulkDecide(
                $period,
                array_map('intval', $request->validated('ids')),
                $reject ? null : $approved,
                $reject
            );
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        $message = $reject
            ? ($result['updated'] === 0 ? 'No se rechazó ningún alumno.' : "Se rechazaron {$result['updated']} alumnos.")
            : ($result['updated'] === 0
                ? 'No se actualizó ninguna decisión.'
                : ($approved
                    ? "Se aprobaron {$result['updated']} alumnos."
                    : "Se reprobaron {$result['updated']} alumnos."));

        return response()->json([
            'success' => true,
            'message' => $message,
            'data' => $result,
        ]);
    }
}
