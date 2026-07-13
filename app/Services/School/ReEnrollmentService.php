<?php

namespace App\Services\School;

use App\Enums\EnrollmentStatus;
use App\Enums\ReEnrollmentEventAction;
use App\Enums\ReEnrollmentPeriodStatus;
use App\Enums\ReEnrollmentProcessStep;
use App\Enums\ReEnrollmentValidationStatus;
use App\Models\Enrollment;
use App\Models\School\ReEnrollmentApplication;
use App\Models\School\ReEnrollmentEvent;
use App\Models\School\ReEnrollmentPeriod;
use App\Services\EnrollmentPromotionService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ReEnrollmentService
{
    public function __construct(
        private readonly EnrollmentPromotionService $promotionService,
        private readonly AcademicYearService $academicYearService
    ) {}

    public function syncApplications(ReEnrollmentPeriod $period): int
    {
        $enrollments = Enrollment::query()
            ->where('academic_year_id', $period->from_academic_year_id)
            ->where('status', EnrollmentStatus::Active->value)
            ->get(['id', 'student_id']);

        $created = 0;

        foreach ($enrollments as $enrollment) {
            $application = ReEnrollmentApplication::firstOrCreate(
                [
                    're_enrollment_period_id' => $period->id,
                    'enrollment_id' => $enrollment->id,
                ],
                [
                    'student_id' => $enrollment->student_id,
                    'status' => ReEnrollmentValidationStatus::PENDING,
                ]
            );

            if ($application->wasRecentlyCreated) {
                $created++;
            }
        }

        return $created;
    }

    public function dashboardStats(ReEnrollmentPeriod $period): array
    {
        $applications = $period->applications();

        $total = (clone $applications)->count();
        $validated = (clone $applications)->where('status', ReEnrollmentValidationStatus::VALIDATED)->count();
        $pending = (clone $applications)->where('status', ReEnrollmentValidationStatus::PENDING)->count();
        $inReview = (clone $applications)->where('status', ReEnrollmentValidationStatus::IN_REVIEW)->count();
        $rejected = (clone $applications)->where('status', ReEnrollmentValidationStatus::REJECTED)->count();
        $withDebts = (clone $applications)->where('no_debts', false)->count();
        $readyForPromotion = (clone $applications)
            ->where('status', ReEnrollmentValidationStatus::VALIDATED)
            ->count();
        $unresolved = $pending + $inReview;

        $percent = $total > 0 ? (int) round(($validated / $total) * 100) : 0;

        return [
            'period_id' => $period->id,
            'period_name' => $period->name,
            'status' => $period->status->value,
            'current_step' => $period->current_step->value,
            'keep_current_groups' => $period->keep_current_groups,
            'promotion_executed_at' => $period->promotion_executed_at?->toIso8601String(),
            'can_validate' => $this->canAccessStep($period, ReEnrollmentProcessStep::VALIDATION),
            'can_promote' => $this->canAccessStep($period, ReEnrollmentProcessStep::PROMOTION)
                && $unresolved === 0
                && $period->promotion_executed_at === null,
            'can_simulate_promotion' => $this->canAccessStep($period, ReEnrollmentProcessStep::PROMOTION) && $unresolved === 0,
            'can_finalize' => $period->promotion_executed_at !== null && $period->status === ReEnrollmentPeriodStatus::OPEN,
            'total_students' => $total,
            'validated' => $validated,
            'pending' => $pending,
            'in_review' => $inReview,
            'rejected' => $rejected,
            'unresolved' => $unresolved,
            'with_debts' => $withDebts,
            'ready_for_promotion' => $readyForPromotion,
            'progress_percent' => $percent,
        ];
    }

    public function canAccessStep(ReEnrollmentPeriod $period, ReEnrollmentProcessStep $step): bool
    {
        if ($period->status === ReEnrollmentPeriodStatus::FINALIZED) {
            return $step === ReEnrollmentProcessStep::COMPLETED;
        }

        if ($period->status !== ReEnrollmentPeriodStatus::OPEN) {
            return $step === ReEnrollmentProcessStep::CONFIGURATION;
        }

        $order = [
            ReEnrollmentProcessStep::CONFIGURATION->value => 0,
            ReEnrollmentProcessStep::VALIDATION->value => 1,
            ReEnrollmentProcessStep::PROMOTION->value => 2,
            ReEnrollmentProcessStep::GROUPS->value => 3,
            ReEnrollmentProcessStep::COMPLETED->value => 4,
        ];

        $current = $order[$period->current_step->value] ?? 0;
        $target = $order[$step->value] ?? 0;

        if ($period->keep_current_groups && $step === ReEnrollmentProcessStep::GROUPS) {
            return false;
        }

        return $target <= $current + 1;
    }

    public function assertStepAccess(ReEnrollmentPeriod $period, ReEnrollmentProcessStep $step): void
    {
        if (! $this->canAccessStep($period, $step)) {
            throw new RuntimeException('Este paso del proceso aún no está disponible.');
        }
    }

    public function assertCanPromote(ReEnrollmentPeriod $period): void
    {
        if ($period->status !== ReEnrollmentPeriodStatus::OPEN) {
            throw new RuntimeException('El periodo de reinscripción debe estar abierto para promover.');
        }

        $this->assertStepAccess($period, ReEnrollmentProcessStep::PROMOTION);

        $unresolved = $period->applications()
            ->whereIn('status', [
                ReEnrollmentValidationStatus::PENDING->value,
                ReEnrollmentValidationStatus::IN_REVIEW->value,
            ])
            ->count();

        if ($unresolved > 0) {
            throw new RuntimeException("Hay {$unresolved} alumnos sin validación final. Completa validación antes de promover.");
        }
    }

    public function assertCanFinalize(ReEnrollmentPeriod $period): void
    {
        if ($period->status !== ReEnrollmentPeriodStatus::OPEN) {
            throw new RuntimeException('Solo se puede finalizar un periodo abierto.');
        }

        if ($period->promotion_executed_at === null) {
            throw new RuntimeException('Debes ejecutar la promoción real antes de finalizar el proceso.');
        }
    }

    public function logEvent(
        ReEnrollmentPeriod $period,
        ReEnrollmentEventAction $action,
        ?array $summary = null,
        ?int $userId = null
    ): ReEnrollmentEvent {
        return ReEnrollmentEvent::create([
            're_enrollment_period_id' => $period->id,
            'action' => $action,
            'user_id' => $userId ?? Auth::id(),
            'summary' => $summary,
        ]);
    }

    public function promotePeriod(ReEnrollmentPeriod $period, bool $dryRun = false): array
    {
        $this->assertCanPromote($period);

        $summary = $this->promotionService->promote(
            $period->from_academic_year_id,
            $period->to_academic_year_id,
            $dryRun
        );

        if (! $dryRun) {
            $period->update([
                'promotion_executed_at' => now(),
                'promotion_executed_by' => Auth::id(),
                'last_promotion_summary' => $summary,
                'current_step' => $period->keep_current_groups
                    ? ReEnrollmentProcessStep::COMPLETED
                    : ReEnrollmentProcessStep::GROUPS,
            ]);

            $this->logEvent($period, ReEnrollmentEventAction::PROMOTION_EXECUTED, $summary);
        } else {
            $this->logEvent($period, ReEnrollmentEventAction::PROMOTION_DRY_RUN, $summary);
        }

        return $summary;
    }

    public function advanceStep(ReEnrollmentPeriod $period, ReEnrollmentProcessStep $step): ReEnrollmentPeriod
    {
        if ($period->status === ReEnrollmentPeriodStatus::FINALIZED) {
            throw new RuntimeException('El proceso ya está finalizado.');
        }

        if ($step === ReEnrollmentProcessStep::PROMOTION) {
            $unresolved = $period->applications()
                ->whereIn('status', [
                    ReEnrollmentValidationStatus::PENDING->value,
                    ReEnrollmentValidationStatus::IN_REVIEW->value,
                ])
                ->count();

            if ($unresolved > 0) {
                throw new RuntimeException("No puedes avanzar a promoción: {$unresolved} alumnos pendientes de validación.");
            }
        }

        $period->update(['current_step' => $step]);
        $this->logEvent($period, ReEnrollmentEventAction::STEP_ADVANCED, ['step' => $step->value]);

        return $period->fresh();
    }

    public function finalize(ReEnrollmentPeriod $period, bool $activateAcademicYear = false): ReEnrollmentPeriod
    {
        $this->assertCanFinalize($period);

        return DB::transaction(function () use ($period, $activateAcademicYear) {
            $stats = $this->dashboardStats($period);

            $period->update([
                'status' => ReEnrollmentPeriodStatus::FINALIZED,
                'current_step' => ReEnrollmentProcessStep::COMPLETED,
                'finalized_at' => now(),
                'finalized_by' => Auth::id(),
            ]);

            $this->logEvent($period, ReEnrollmentEventAction::FINALIZED, $stats);

            if ($activateAcademicYear) {
                $toYear = $period->toAcademicYear;
                if ($toYear) {
                    $this->academicYearService->activate($toYear);
                    $this->logEvent($period, ReEnrollmentEventAction::ACADEMIC_YEAR_ACTIVATED, [
                        'academic_year_id' => $toYear->id,
                        'description' => $toYear->description,
                    ]);
                }
            }

            return $period->fresh();
        });
    }

    public function getHistory(ReEnrollmentPeriod $period): array
    {
        return $period->events()
            ->with('user:id,name,email')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (ReEnrollmentEvent $event) => [
                'id' => $event->id,
                'action' => $event->action->value,
                'user_name' => $event->user?->name ?? 'Sistema',
                'summary' => $event->summary,
                'created_at' => $event->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }
}
