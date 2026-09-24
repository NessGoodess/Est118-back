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
        $withDebts = (clone $applications)
            ->where(fn ($q) => $q->where('no_debts', false)->orWhereNull('no_debts'))
            ->count();
        $pendingGradeDecisions = (clone $applications)
            ->where('status', '!=', ReEnrollmentValidationStatus::REJECTED->value)
            ->whereNull('passed_cycle')
            ->count();
        $readyForPromotion = $this->decidedActiveOriginCount($period);
        $unresolved = $pending + $inReview;
        $missingEnrollmentDecisions = $this->missingOriginEnrollmentDecisions($period);
        $unsyncedEnrollments = $this->unsyncedOriginEnrollmentsCount($period);
        $waitingActivation = $this->waitingActivationCount($period);
        $canAccessPromotion = $this->canAccessStep($period, ReEnrollmentProcessStep::PROMOTION);
        $canDecide = $canAccessPromotion && $period->status === ReEnrollmentPeriodStatus::OPEN;
        $canPromote = $canDecide
            && $period->promotion_executed_at === null
            && $readyForPromotion > 0;
        $canPlaceLate = $canDecide
            && $period->promotion_executed_at !== null
            && $readyForPromotion > 0;

        $resolved = $validated + $rejected;
        $percent = $total > 0 ? (int) round(($resolved / $total) * 100) : 0;

        return [
            'period_id' => $period->id,
            'period_name' => $period->name,
            'status' => $period->status->value,
            'current_step' => $period->current_step->value,
            'keep_current_groups' => $period->keep_current_groups,
            'promotion_executed_at' => $period->promotion_executed_at?->toIso8601String(),
            'can_validate' => $this->canAccessStep($period, ReEnrollmentProcessStep::VALIDATION),
            'can_decide' => $canDecide,
            'can_promote' => $canPromote,
            'can_place_late' => $canPlaceLate,
            'can_simulate_promotion' => $canPromote || $canPlaceLate,
            'can_finalize' => $period->promotion_executed_at !== null && $period->status === ReEnrollmentPeriodStatus::OPEN,
            'total_students' => $total,
            'validated' => $validated,
            'pending' => $pending,
            'in_review' => $inReview,
            'rejected' => $rejected,
            'unresolved' => $unresolved,
            'pending_grade_decisions' => $pendingGradeDecisions,
            'missing_enrollment_decisions' => $missingEnrollmentDecisions,
            'unsynced_enrollments' => $unsyncedEnrollments,
            'waiting_activation' => $waitingActivation,
            'not_promoted_yet' => $missingEnrollmentDecisions,
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

    public function assertCanValidate(ReEnrollmentPeriod $period): void
    {
        if ($period->status === ReEnrollmentPeriodStatus::FINALIZED) {
            throw new RuntimeException('El periodo está finalizado. Solo consulta.');
        }

        if ($period->status !== ReEnrollmentPeriodStatus::OPEN) {
            throw new RuntimeException('Abre el periodo de reinscripción para validar alumnos.');
        }

        $this->assertStepAccess($period, ReEnrollmentProcessStep::VALIDATION);
    }

    public function unresolvedCount(ReEnrollmentPeriod $period): int
    {
        return $period->applications()
            ->whereIn('status', [
                ReEnrollmentValidationStatus::PENDING->value,
                ReEnrollmentValidationStatus::IN_REVIEW->value,
            ])
            ->count();
    }

    public function missingOriginEnrollmentDecisions(ReEnrollmentPeriod $period): int
    {
        return Enrollment::query()
            ->where('academic_year_id', $period->from_academic_year_id)
            ->where('status', EnrollmentStatus::Active->value)
            ->whereNull('is_approved')
            ->count();
    }

    public function unsyncedOriginEnrollmentsCount(ReEnrollmentPeriod $period): int
    {
        return Enrollment::query()
            ->where('academic_year_id', $period->from_academic_year_id)
            ->where('status', EnrollmentStatus::Active->value)
            ->whereNotIn('id', $period->applications()->select('enrollment_id'))
            ->count();
    }

    public function decidedActiveOriginCount(ReEnrollmentPeriod $period): int
    {
        return Enrollment::query()
            ->where('academic_year_id', $period->from_academic_year_id)
            ->where('status', EnrollmentStatus::Active->value)
            ->whereNotNull('is_approved')
            ->count();
    }

    public function waitingActivationCount(ReEnrollmentPeriod $period): int
    {
        $studentIds = $period->applications()->select('student_id');

        return Enrollment::query()
            ->where('academic_year_id', $period->to_academic_year_id)
            ->where('status', EnrollmentStatus::PreEnrolled->value)
            ->whereIn('student_id', $studentIds)
            ->count();
    }

    /**
     * @param  list<'debts'|'data_update'>  $scopes
     * @return array{updated: int, newly_validated: int, scopes: list<string>, grade: ?string, group: ?string}
     */
    public function bulkValidateChecklist(
        ReEnrollmentPeriod $period,
        array $scopes,
        ?string $grade = null,
        ?string $group = null
    ): array {
        $this->assertCanValidate($period);

        $fields = $this->checklistFieldsForScopes($scopes);

        if ($fields === []) {
            throw new RuntimeException('Selecciona al menos un rubro para validar.');
        }

        $query = $period->applications()
            ->with('enrollment')
            ->where('status', '!=', ReEnrollmentValidationStatus::REJECTED->value);

        if ($grade) {
            $query->whereHas(
                'enrollment.classGroup.gradeLevel',
                fn ($q) => $q->where('name', $grade)
            );
        }

        if ($group) {
            $query->whereHas(
                'enrollment.classGroup',
                fn ($q) => $q->where('name', $group)
            );
        }

        $updated = 0;
        $newlyValidated = 0;

        DB::transaction(function () use ($period, $query, $fields, $scopes, $grade, $group, &$updated, &$newlyValidated) {
            foreach ($query->get() as $application) {
                $wasValidated = $application->status === ReEnrollmentValidationStatus::VALIDATED;
                $payload = $fields;

                if ($application->status === ReEnrollmentValidationStatus::PENDING) {
                    $payload['status'] = ReEnrollmentValidationStatus::IN_REVIEW;
                }

                $application->update($payload);
                $this->applyChecklistOutcome($application->fresh());

                $updated++;

                if (! $wasValidated && $application->fresh()->status === ReEnrollmentValidationStatus::VALIDATED) {
                    $newlyValidated++;
                }
            }

            $this->logEvent($period, ReEnrollmentEventAction::BULK_VALIDATED, [
                'scopes' => $scopes,
                'grade' => $grade,
                'group' => $group,
                'updated' => $updated,
                'newly_validated' => $newlyValidated,
            ]);
        });

        return [
            'updated' => $updated,
            'newly_validated' => $newlyValidated,
            'scopes' => $scopes,
            'grade' => $grade,
            'group' => $group,
        ];
    }

    /**
     * @param  list<'debts'|'data_update'>  $scopes
     * @return array<string, bool>
     */
    public function checklistFieldsForScopes(array $scopes): array
    {
        $fields = [];

        if (in_array('debts', $scopes, true)) {
            $fields['no_debts'] = true;
        }

        if (in_array('data_update', $scopes, true)) {
            $fields['guardian_updated'] = true;
            $fields['phone_updated'] = true;
            $fields['address_updated'] = true;
            $fields['photo_updated'] = true;
        }

        if (in_array('documents', $scopes, true)) {
            $fields['documents_complete'] = true;
        }

        return $fields;
    }

    public function applyChecklistOutcome(ReEnrollmentApplication $application): void
    {
        if (
            ! $application->isChecklistComplete()
            || $application->status === ReEnrollmentValidationStatus::REJECTED
        ) {
            return;
        }

        $application->update(['status' => ReEnrollmentValidationStatus::VALIDATED]);
        $this->activateWaitingDestination($application->fresh() ?? $application);
    }

    public function applyGradeDecision(ReEnrollmentApplication $application, bool $approved): void
    {
        if ($application->status === ReEnrollmentValidationStatus::REJECTED) {
            throw new RuntimeException('No se puede decidir una solicitud rechazada o dada de baja.');
        }

        $origin = $application->enrollment;
        if ($origin && $origin->status === EnrollmentStatus::Completed) {
            throw new RuntimeException('Este alumno ya fue promovido. La decisión de grado no se puede cambiar.');
        }

        $application->update(['passed_cycle' => $approved]);

        if ($origin) {
            $origin->update(['is_approved' => $approved]);
        }
    }

    public function confirmPresence(ReEnrollmentApplication $application): Enrollment
    {
        $period = $application->period;
        if (! $period) {
            $application->loadMissing('period');
            $period = $application->period;
        }

        if (! $period) {
            throw new RuntimeException('La solicitud no tiene periodo.');
        }

        $this->assertCanValidate($period);

        $dest = $this->destinationEnrollment($application, $period);
        if (! $dest) {
            throw new RuntimeException('Aún no hay inscripción en el ciclo destino. Colócalo en Promoción.');
        }

        if ($dest->status === EnrollmentStatus::Dropped) {
            throw new RuntimeException('Esta inscripción ya fue dada de baja.');
        }

        if ($dest->status === EnrollmentStatus::PreEnrolled) {
            $dest->update(['status' => EnrollmentStatus::Active]);
            $this->logEvent($period, ReEnrollmentEventAction::PRESENCE_CONFIRMED, [
                'application_id' => $application->id,
                'enrollment_id' => $dest->id,
            ]);
        }

        return $dest->fresh() ?? $dest;
    }

    public function confirmDropout(ReEnrollmentApplication $application): void
    {
        $period = $application->period ?? $application->loadMissing('period')->period;
        if (! $period) {
            throw new RuntimeException('La solicitud no tiene periodo.');
        }

        $this->assertCanValidate($period);

        $application->update([
            'status' => ReEnrollmentValidationStatus::REJECTED,
        ]);

        $origin = $application->enrollment;
        if ($origin && in_array($origin->status, [EnrollmentStatus::Active, EnrollmentStatus::PreEnrolled], true)) {
            $origin->update(['status' => EnrollmentStatus::Dropped]);
        }

        $dest = $this->destinationEnrollment($application, $period);
        if ($dest && in_array($dest->status, [EnrollmentStatus::Active, EnrollmentStatus::PreEnrolled], true)) {
            $dest->update(['status' => EnrollmentStatus::Dropped]);
        }

        $this->logEvent($period, ReEnrollmentEventAction::DROPOUT_CONFIRMED, [
            'application_id' => $application->id,
            'student_id' => $application->student_id,
        ]);
    }

    public function rejectApplication(ReEnrollmentApplication $application): void
    {
        $this->confirmDropout($application);
    }

    public function destinationEnrollment(
        ReEnrollmentApplication $application,
        ?ReEnrollmentPeriod $period = null
    ): ?Enrollment {
        $period ??= $application->period ?? $application->loadMissing('period')->period;
        if (! $period) {
            return null;
        }

        return Enrollment::query()
            ->where('student_id', $application->student_id)
            ->where('academic_year_id', $period->to_academic_year_id)
            ->latest('id')
            ->first();
    }

    public function activateWaitingDestination(ReEnrollmentApplication $application): void
    {
        $period = $application->period ?? $application->loadMissing('period')->period;
        $dest = $this->destinationEnrollment($application, $period);
        if ($dest && $dest->status === EnrollmentStatus::PreEnrolled) {
            $dest->update(['status' => EnrollmentStatus::Active]);
            if ($period) {
                $this->logEvent($period, ReEnrollmentEventAction::PRESENCE_CONFIRMED, [
                    'application_id' => $application->id,
                    'enrollment_id' => $dest->id,
                    'via' => 'checklist',
                ]);
            }
        }
    }

    /**
     * @param  list<int>  $ids
     * @return array{updated: int, skipped: int, rejected: int, approved: bool|null}
     */
    public function bulkDecide(
        ReEnrollmentPeriod $period,
        array $ids,
        ?bool $approved,
        bool $reject = false
    ): array {
        if ($reject) {
            $this->assertCanValidate($period);
        } else {
            $this->assertCanDecide($period);
        }

        if (! $reject && $approved === null) {
            throw new RuntimeException('Indica si se aprueba o se reprueba.');
        }

        $updated = 0;
        $skipped = 0;
        $rejectedCount = 0;

        DB::transaction(function () use ($period, $ids, $approved, $reject, &$updated, &$skipped, &$rejectedCount) {
            $applications = $period->applications()
                ->with('enrollment')
                ->whereIn('id', $ids)
                ->get();

            foreach ($applications as $application) {
                if ($reject) {
                    if ($application->status === ReEnrollmentValidationStatus::REJECTED) {
                        $skipped++;
                        continue;
                    }
                    $this->rejectApplication($application);
                    $rejectedCount++;
                    $updated++;
                    continue;
                }

                if ($application->status === ReEnrollmentValidationStatus::REJECTED) {
                    $skipped++;
                    continue;
                }

                $origin = $application->enrollment;
                if ($origin && $origin->status === EnrollmentStatus::Completed) {
                    $skipped++;
                    continue;
                }

                $this->applyGradeDecision($application, (bool) $approved);
                $updated++;
            }

            $this->logEvent($period, ReEnrollmentEventAction::BULK_DECIDED, [
                'ids' => $ids,
                'approved' => $approved,
                'reject' => $reject,
                'updated' => $updated,
                'skipped' => $skipped,
                'rejected' => $rejectedCount,
            ]);
        });

        return [
            'updated' => $updated,
            'skipped' => $skipped,
            'rejected' => $rejectedCount,
            'approved' => $approved,
        ];
    }

    public function assertCanDecide(ReEnrollmentPeriod $period): void
    {
        $this->assertCanValidate($period);
        $this->assertStepAccess($period, ReEnrollmentProcessStep::PROMOTION);
    }

    public function assertCanPromote(ReEnrollmentPeriod $period): void
    {
        $this->syncApplications($period);
        $this->assertCanDecide($period);

        if ($this->decidedActiveOriginCount($period) === 0) {
            throw new RuntimeException('No hay alumnos con decisión de grado para promover. Aprueba o reprueba al menos uno.');
        }
    }

    public function assertCanPlaceLate(ReEnrollmentPeriod $period): void
    {
        $this->assertCanDecide($period);

        if ($period->promotion_executed_at === null) {
            throw new RuntimeException('Primero ejecuta la promoción del lote principal.');
        }

        if ($this->decidedActiveOriginCount($period) === 0) {
            throw new RuntimeException('No hay alumnos pendientes de colocar con decisión de grado.');
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
        $alreadyExecuted = $period->promotion_executed_at !== null;

        if ($alreadyExecuted) {
            $this->syncApplications($period);
            $this->assertCanPlaceLate($period);
        } else {
            $this->assertCanPromote($period);
        }

        $applications = $period->applications()->get()->keyBy('enrollment_id');

        $summary = $this->promotionService->promote(
            $period->from_academic_year_id,
            $period->to_academic_year_id,
            $dryRun,
            function (Enrollment $enrollment) use ($applications): EnrollmentStatus {
                $application = $applications->get($enrollment->id);
                if (
                    $application
                    && $application->isChecklistComplete()
                    && $application->status === ReEnrollmentValidationStatus::VALIDATED
                ) {
                    return EnrollmentStatus::Active;
                }

                return EnrollmentStatus::PreEnrolled;
            }
        );

        if (! $dryRun) {
            if (! $alreadyExecuted) {
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
                $period->update(['last_promotion_summary' => $summary]);
                $this->logEvent($period, ReEnrollmentEventAction::LATE_PLACED, $summary);
            }
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
