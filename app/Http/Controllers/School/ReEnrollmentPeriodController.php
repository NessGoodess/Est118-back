<?php

namespace App\Http\Controllers\School;

use App\Enums\ReEnrollmentEventAction;
use App\Enums\ReEnrollmentPeriodStatus;
use App\Enums\ReEnrollmentProcessStep;
use App\Http\Controllers\Controller;
use App\Http\Requests\School\FinalizeReEnrollmentPeriodRequest;
use App\Http\Requests\School\PromoteReEnrollmentPeriodRequest;
use App\Http\Requests\School\StoreReEnrollmentPeriodRequest;
use App\Http\Requests\School\UpdateReEnrollmentPeriodRequest;
use App\Models\School\ReEnrollmentPeriod;
use App\Services\School\ReEnrollmentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReEnrollmentPeriodController extends Controller implements HasMiddleware
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

    public function index(): JsonResponse
    {
        $periods = ReEnrollmentPeriod::query()
            ->with(['fromAcademicYear:id,description,year_start,year_end', 'toAcademicYear:id,description,year_start,year_end'])
            ->withCount('applications')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['success' => true, 'data' => $periods]);
    }

    public function store(StoreReEnrollmentPeriodRequest $request): JsonResponse
    {
        $period = ReEnrollmentPeriod::create([
            ...$request->validated(),
            'created_by' => Auth::id(),
            'status' => ReEnrollmentPeriodStatus::DRAFT,
            'current_step' => ReEnrollmentProcessStep::CONFIGURATION,
        ]);

        return response()->json(['success' => true, 'data' => $period], 201);
    }

    public function show(ReEnrollmentPeriod $period): JsonResponse
    {
        $period->load(['fromAcademicYear', 'toAcademicYear', 'finalizedBy:id,name', 'promotionExecutedBy:id,name']);

        return response()->json([
            'success' => true,
            'data' => $period,
            'stats' => $this->reEnrollmentService->dashboardStats($period),
            'history' => $this->reEnrollmentService->getHistory($period),
        ]);
    }

    public function update(UpdateReEnrollmentPeriodRequest $request, ReEnrollmentPeriod $period): JsonResponse
    {
        if ($period->status === ReEnrollmentPeriodStatus::FINALIZED) {
            return response()->json([
                'success' => false,
                'message' => 'El proceso ya está finalizado y no se puede modificar.',
            ], 422);
        }

        $period->update($request->validated());

        return response()->json(['success' => true, 'data' => $period->fresh()]);
    }

    public function open(ReEnrollmentPeriod $period): JsonResponse
    {
        if ($period->status === ReEnrollmentPeriodStatus::FINALIZED) {
            return response()->json(['success' => false, 'message' => 'No se puede abrir un proceso finalizado.'], 422);
        }

        DB::transaction(function () use ($period) {
            ReEnrollmentPeriod::query()
                ->where('status', ReEnrollmentPeriodStatus::OPEN)
                ->where('id', '!=', $period->id)
                ->update(['status' => ReEnrollmentPeriodStatus::CLOSED]);

            $period->update([
                'status' => ReEnrollmentPeriodStatus::OPEN,
                'current_step' => ReEnrollmentProcessStep::VALIDATION,
            ]);

            $this->reEnrollmentService->syncApplications($period);
            $this->reEnrollmentService->logEvent($period, ReEnrollmentEventAction::OPENED, [
                'applications_synced' => $period->applications()->count(),
            ]);
        });

        return response()->json([
            'success' => true,
            'message' => 'Periodo de reinscripción abierto.',
            'data' => $period->fresh(),
            'stats' => $this->reEnrollmentService->dashboardStats($period),
        ]);
    }

    public function close(ReEnrollmentPeriod $period): JsonResponse
    {
        $period->update(['status' => ReEnrollmentPeriodStatus::CLOSED]);
        $this->reEnrollmentService->logEvent($period, ReEnrollmentEventAction::CLOSED);

        return response()->json(['success' => true, 'data' => $period->fresh()]);
    }

    public function dashboard(ReEnrollmentPeriod $period): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->reEnrollmentService->dashboardStats($period),
        ]);
    }

    public function history(ReEnrollmentPeriod $period): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->reEnrollmentService->getHistory($period),
        ]);
    }

    public function advanceStep(ReEnrollmentPeriod $period): JsonResponse
    {
        try {
            $next = match ($period->current_step) {
                ReEnrollmentProcessStep::CONFIGURATION => ReEnrollmentProcessStep::VALIDATION,
                ReEnrollmentProcessStep::VALIDATION => ReEnrollmentProcessStep::PROMOTION,
                ReEnrollmentProcessStep::PROMOTION => $period->keep_current_groups
                    ? ReEnrollmentProcessStep::COMPLETED
                    : ReEnrollmentProcessStep::GROUPS,
                ReEnrollmentProcessStep::GROUPS => ReEnrollmentProcessStep::COMPLETED,
                default => ReEnrollmentProcessStep::COMPLETED,
            };

            $updated = $this->reEnrollmentService->advanceStep($period, $next);
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $updated]);
    }

    public function promote(PromoteReEnrollmentPeriodRequest $request, ReEnrollmentPeriod $period): JsonResponse
    {
        try {
            $summary = $this->reEnrollmentService->promotePeriod(
                $period,
                (bool) $request->boolean('dry_run', false)
            );
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => $request->boolean('dry_run') ? 'Simulación completada.' : 'Promoción ejecutada.',
            'data' => $summary,
            'period' => $period->fresh(),
            'stats' => $this->reEnrollmentService->dashboardStats($period),
        ]);
    }

    public function finalize(FinalizeReEnrollmentPeriodRequest $request, ReEnrollmentPeriod $period): JsonResponse
    {
        try {
            $finalized = $this->reEnrollmentService->finalize(
                $period,
                (bool) $request->boolean('activate_academic_year', false)
            );
        } catch (RuntimeException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Proceso de reinscripción finalizado.',
            'data' => $finalized,
            'stats' => $this->reEnrollmentService->dashboardStats($finalized),
            'history' => $this->reEnrollmentService->getHistory($finalized),
        ]);
    }
}
