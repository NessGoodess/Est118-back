<?php

namespace App\Services\Print;

use App\Enums\CredentialPrintReason;
use App\Enums\CredentialSideStatus;
use App\Enums\PrintBatchStrategy;
use App\Enums\PrintJobStatus;
use App\Models\CredentialPrint;
use App\Models\PrintJob;
use App\Models\StudentCredentialTracking;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CredentialPrintService
{
    public function __construct(
        private readonly PrintJobService $printJobs
    ) {}

    /**
     * @param  array<int, int>  $studentIds
     * @return array{batch_uuid: string, strategy: PrintBatchStrategy, cards: Collection<int, CredentialPrint>}
     */
    public function createBatch(
        array $studentIds,
        User $user,
        string $templateKey = 'student-card-v1',
        PrintBatchStrategy $strategy = PrintBatchStrategy::FrontsThenBacks,
        string $printerId = PrintJobService::DEFAULT_PRINTER_ID
    ): array {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
        if ($studentIds === []) {
            throw ValidationException::withMessages([
                'student_ids' => ['Se requiere al menos un alumno.'],
            ]);
        }

        $batchUuid = (string) Str::uuid();

        return DB::transaction(function () use ($studentIds, $user, $templateKey, $strategy, $printerId, $batchUuid) {
            $cards = collect();

            foreach ($studentIds as $studentId) {
                $student = $this->printJobs->studentForPrint($studentId);
                $design = $this->printJobs->resolveDesignForStudent($student, $templateKey);
                $facesMode = ($design->faces_mode ?? 'single') === 'double' ? 'double' : 'single';
                if ($strategy === PrintBatchStrategy::BackOnly && $facesMode !== 'double') {
                    throw ValidationException::withMessages([
                        'strategy' => ["{$student->id} no tiene un diseño de dos caras. No se puede encolar solo el reverso."],
                    ]);
                }
                $yearId = $student->currentEnrollment?->academic_year_id
                    ?? $student->enrollments()->where('status', 'active')->first()?->academic_year_id;

                $frontPrinted = $strategy === PrintBatchStrategy::BackOnly;
                $card = CredentialPrint::query()->create([
                    'student_id' => $student->id,
                    'academic_year_id' => $yearId,
                    'card_design_id' => $design->id,
                    'faces_mode' => $facesMode,
                    'front_status' => $frontPrinted
                        ? CredentialSideStatus::Printed
                        : CredentialSideStatus::Pending,
                    'back_status' => $facesMode === 'double'
                        ? CredentialSideStatus::Pending
                        : CredentialSideStatus::NotApplicable,
                    'strategy' => $strategy,
                    'batch_uuid' => $batchUuid,
                    'reason' => $this->resolveReason((int) $student->id, $yearId ? (int) $yearId : null),
                    'created_by' => $user->id,
                    'front_printed_at' => $frontPrinted ? now() : null,
                ]);
                $cards->push($card);
            }

            if ($strategy === PrintBatchStrategy::BackOnly) {
                $this->enqueue($cards->pluck('id')->all(), 'back', $user, $printerId);
            } elseif ($strategy === PrintBatchStrategy::PerCard) {
                $this->enqueue([$cards->first()->id], 'front', $user, $printerId);
            } else {
                $this->enqueue($cards->pluck('id')->all(), 'front', $user, $printerId);
            }

            return [
                'batch_uuid' => $batchUuid,
                'strategy' => $strategy,
                'cards' => $this->cardsWithRelations(
                    CredentialPrint::query()->where('batch_uuid', $batchUuid)->orderBy('id')->get()
                ),
            ];
        });
    }

    /**
     * @param  array<int, int>  $cardIds
     * @return Collection<int, PrintJob>
     */
    public function enqueue(
        array $cardIds,
        string $side,
        User $user,
        string $printerId = PrintJobService::DEFAULT_PRINTER_ID
    ): Collection {
        $side = $side === 'back' ? 'back' : 'front';
        $cardIds = array_values(array_unique(array_map('intval', $cardIds)));
        if ($cardIds === []) {
            throw ValidationException::withMessages([
                'credential_print_ids' => ['Se requiere al menos una tarjeta.'],
            ]);
        }

        return DB::transaction(function () use ($cardIds, $side, $user, $printerId) {
            $cards = CredentialPrint::query()
                ->whereIn('id', $cardIds)
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            if ($cards->count() !== count($cardIds)) {
                throw ValidationException::withMessages([
                    'credential_print_ids' => ['Una o más tarjetas no existen.'],
                ]);
            }

            $ordered = $side === 'back'
                ? $this->orderForBacks($cards, $cardIds)
                : collect($cardIds)->map(fn (int $id) => $cards->get($id))->filter();

            $jobs = collect();
            foreach ($ordered as $card) {
                $this->assertCanEnqueue($card, $side);
                $jobs->push($this->printJobs->createJobForCard($card, $user, $side, $printerId));
            }

            return $jobs;
        });
    }

    /**
     * @param  array<int, int>|null  $cardIds
     * @return Collection<int, CredentialPrint>
     */
    public function discard(
        User $user,
        ?array $cardIds = null,
        ?string $batchUuid = null,
        string $reason = 'Descartado por el operador'
    ): Collection {
        $reason = trim($reason) !== '' ? trim($reason) : 'Descartado por el operador';

        return DB::transaction(function () use ($user, $cardIds, $batchUuid, $reason) {
            $query = CredentialPrint::query()->lockForUpdate()->whereNull('discarded_at');
            if ($batchUuid) {
                $query->where('batch_uuid', $batchUuid);
            } elseif (is_array($cardIds) && $cardIds !== []) {
                $query->whereIn('id', array_map('intval', $cardIds));
            } else {
                throw ValidationException::withMessages([
                    'credential_print_ids' => ['Indica tarjetas o un lote para descartar.'],
                ]);
            }

            $cards = $query->get();
            foreach ($cards as $card) {
                $front = $card->front_status;
                $back = $card->back_status;
                if ($front === CredentialSideStatus::Pending) {
                    $front = CredentialSideStatus::Cancelled;
                }
                if ($back === CredentialSideStatus::Pending) {
                    $back = CredentialSideStatus::Cancelled;
                }
                $card->update([
                    'front_status' => $front,
                    'back_status' => $back,
                    'discarded_by' => $user->id,
                    'discarded_at' => now(),
                    'discard_reason' => $reason,
                ]);
            }

            return $this->cardsWithRelations($cards);
        });
    }

    public function markSide(int $cardId, string $side, User $user): CredentialPrint
    {
        $side = $side === 'back' ? 'back' : 'front';

        return DB::transaction(function () use ($cardId, $side) {
            $card = CredentialPrint::query()->lockForUpdate()->find($cardId);
            if (! $card) {
                throw ValidationException::withMessages([
                    'credential_print_id' => ['La tarjeta no existe.'],
                ]);
            }
            if ($card->discarded_at) {
                throw ValidationException::withMessages([
                    'credential_print_id' => ['La tarjeta ya fue descartada.'],
                ]);
            }
            if ($card->activeJob()) {
                throw ValidationException::withMessages([
                    'credential_print_id' => ['La tarjeta tiene un trabajo activo. Cancélalo o espera a que termine.'],
                ]);
            }

            if ($side === 'front') {
                if ($card->front_status !== CredentialSideStatus::Pending) {
                    throw ValidationException::withMessages([
                        'side' => ['El frente no está pendiente.'],
                    ]);
                }
                $card->front_status = CredentialSideStatus::Printed;
                $card->front_printed_at = now();
            } else {
                if ($card->back_status === CredentialSideStatus::NotApplicable) {
                    throw ValidationException::withMessages([
                        'side' => ['Este diseño no tiene reverso.'],
                    ]);
                }
                if ($card->back_status !== CredentialSideStatus::Pending) {
                    throw ValidationException::withMessages([
                        'side' => ['El reverso no está pendiente.'],
                    ]);
                }
                $card->back_status = CredentialSideStatus::Printed;
                $card->back_printed_at = now();
            }

            if ($card->isComplete() && ! $card->completed_at) {
                $card->completed_at = now();
                $this->markCredentialPrinted($card);
            }
            $card->save();

            return $this->cardsWithRelations(
                CredentialPrint::query()->whereKey($card->id)->get()
            )->first();
        });
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function pending(): Collection
    {
        $cards = $this->cardsWithRelations(
            CredentialPrint::query()
                ->whereNull('discarded_at')
                ->where(function ($q) {
                    $q->where(function ($inner) {
                        $inner->where('front_status', CredentialSideStatus::Pending);
                    })->orWhere(function ($inner) {
                        $inner->where('front_status', CredentialSideStatus::Printed)
                            ->where('back_status', CredentialSideStatus::Pending);
                    });
                })
                ->orderBy('id')
                ->get()
        );

        $withoutActive = $cards->filter(fn (CredentialPrint $card) => $card->activeJob() === null);

        return $withoutActive
            ->groupBy('batch_uuid')
            ->map(function (Collection $group) {
                /** @var CredentialPrint $first */
                $first = $group->first();

                return [
                    'batch_uuid' => $first->batch_uuid,
                    'strategy' => $first->strategy instanceof PrintBatchStrategy
                        ? $first->strategy->value
                        : (string) $first->strategy,
                    'reason' => $first->reason instanceof CredentialPrintReason
                        ? $first->reason->value
                        : (string) $first->reason,
                    'created_at' => $first->created_at?->toIso8601String(),
                    'pending_count' => $group->count(),
                    'cards' => $group->values(),
                ];
            })
            ->values();
    }

    /**
     * @return array{batch_uuid: string, strategy: string, cards: Collection<int, CredentialPrint>}
     */
    public function batch(string $batchUuid): array
    {
        $cards = $this->cardsWithRelations(
            CredentialPrint::query()->where('batch_uuid', $batchUuid)->orderBy('id')->get()
        );
        if ($cards->isEmpty()) {
            throw ValidationException::withMessages([
                'batch_uuid' => ['Lote no encontrado.'],
            ]);
        }

        /** @var CredentialPrint $first */
        $first = $cards->first();

        return [
            'batch_uuid' => $batchUuid,
            'strategy' => $first->strategy instanceof PrintBatchStrategy
                ? $first->strategy->value
                : (string) $first->strategy,
            'cards' => $cards,
        ];
    }

    /**
     * @return Collection<int, CredentialPrint>
     */
    public function historyForStudent(int $studentId): Collection
    {
        return $this->cardsWithRelations(
            CredentialPrint::query()
                ->where('student_id', $studentId)
                ->orderByDesc('id')
                ->get()
        );
    }

    /**
     * @param  array<int, int>  $studentIds
     * @return Collection<int, CredentialPrint>
     */
    public function historyForStudents(array $studentIds): Collection
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
        if ($studentIds === []) {
            return collect();
        }

        return $this->cardsWithRelations(
            CredentialPrint::query()
                ->whereIn('student_id', $studentIds)
                ->orderByDesc('id')
                ->get()
        );
    }

    /**
     * @param  array<int, int>  $studentIds
     * @return array<int, CredentialPrint>
     */
    public function latestByStudentIds(array $studentIds): array
    {
        $studentIds = array_values(array_unique(array_map('intval', $studentIds)));
        if ($studentIds === []) {
            return [];
        }

        $cards = $this->cardsWithRelations(
            CredentialPrint::query()
                ->whereIn('student_id', $studentIds)
                ->orderByDesc('id')
                ->get()
        );

        $latest = [];
        foreach ($cards as $card) {
            if (! isset($latest[$card->student_id])) {
                $latest[$card->student_id] = $card;
            }
        }

        return $latest;
    }

    public function syncFromJob(PrintJob $job): void
    {
        if (! $job->credential_print_id) {
            return;
        }

        $card = CredentialPrint::query()
            ->lockForUpdate()
            ->find($job->credential_print_id);
        if (! $card || $card->discarded_at) {
            return;
        }

        $status = $job->status instanceof PrintJobStatus ? $job->status : PrintJobStatus::from((string) $job->status);
        $side = $job->side_mode === 'back' ? 'back' : 'front';

        if ($status === PrintJobStatus::Completed) {
            if ($side === 'back') {
                $card->back_status = CredentialSideStatus::Printed;
                $card->back_printed_at = $job->completed_at ?? now();
            } else {
                $card->front_status = CredentialSideStatus::Printed;
                $card->front_printed_at = $job->completed_at ?? now();
            }
            if ($card->isComplete() && ! $card->completed_at) {
                $card->completed_at = now();
                $this->markCredentialPrinted($card);
            }
            $card->save();

            return;
        }

        if ($status === PrintJobStatus::Failed) {
            if ($side === 'front') {
                $card->front_status = CredentialSideStatus::Failed;
            }
            $card->save();

            return;
        }

        if ($status === PrintJobStatus::Cancelled) {
            $otherPrinted = $side === 'front'
                ? $card->back_status === CredentialSideStatus::Printed
                : $card->front_status === CredentialSideStatus::Printed;

            if ($otherPrinted) {
                if ($side === 'front' && $card->front_status === CredentialSideStatus::Pending) {
                    $card->front_status = CredentialSideStatus::Pending;
                }
                if ($side === 'back' && $card->back_status !== CredentialSideStatus::Printed) {
                    $card->back_status = CredentialSideStatus::Pending;
                }
            } else {
                $printedNothing = $card->front_status !== CredentialSideStatus::Printed
                    && $card->back_status !== CredentialSideStatus::Printed;
                if ($printedNothing) {
                    if ($card->front_status === CredentialSideStatus::Pending) {
                        $card->front_status = CredentialSideStatus::Cancelled;
                    }
                    if ($card->back_status === CredentialSideStatus::Pending) {
                        $card->back_status = CredentialSideStatus::Cancelled;
                    }
                } elseif ($side === 'front' && $card->front_status === CredentialSideStatus::Pending) {
                    $card->front_status = CredentialSideStatus::Cancelled;
                    if ($card->back_status === CredentialSideStatus::Pending) {
                        $card->back_status = CredentialSideStatus::Cancelled;
                    }
                }
            }
            $card->save();
        }
    }

    private function markCredentialPrinted(CredentialPrint $card): void
    {
        if (! $card->academic_year_id) {
            return;
        }

        StudentCredentialTracking::query()->updateOrCreate(
            [
                'student_id' => $card->student_id,
                'academic_year_id' => $card->academic_year_id,
            ],
            [
                'credential_printed' => true,
            ]
        );
    }

    private function resolveReason(int $studentId, ?int $yearId): CredentialPrintReason
    {
        if ($yearId) {
            $tracking = StudentCredentialTracking::query()
                ->where('student_id', $studentId)
                ->where('academic_year_id', $yearId)
                ->first();
            if ($tracking?->lost) {
                return CredentialPrintReason::Replacement;
            }
        }

        $completeQuery = CredentialPrint::query()
            ->where('student_id', $studentId)
            ->whereNotNull('completed_at');
        if ($yearId) {
            $completeQuery->where('academic_year_id', $yearId);
        }
        if ($completeQuery->exists()) {
            return CredentialPrintReason::Reprint;
        }

        return CredentialPrintReason::Initial;
    }

    private function assertCanEnqueue(CredentialPrint $card, string $side): void
    {
        if ($card->discarded_at) {
            throw ValidationException::withMessages([
                'credential_print_ids' => ["La tarjeta {$card->uuid} ya fue descartada."],
            ]);
        }
        if ($card->activeJob()) {
            throw ValidationException::withMessages([
                'credential_print_ids' => ["La tarjeta {$card->uuid} ya tiene un trabajo activo."],
            ]);
        }
        if ($side === 'front') {
            if ($card->front_status !== CredentialSideStatus::Pending) {
                throw ValidationException::withMessages([
                    'side' => ["La tarjeta {$card->uuid} no tiene el frente pendiente."],
                ]);
            }

            return;
        }
        if ($card->front_status !== CredentialSideStatus::Printed || $card->back_status !== CredentialSideStatus::Pending) {
            throw ValidationException::withMessages([
                'side' => ["La tarjeta {$card->uuid} no está lista para el reverso."],
            ]);
        }
    }

    /**
     * @param  Collection<int, CredentialPrint>  $cards
     * @param  array<int, int>  $requestedIds
     * @return Collection<int, CredentialPrint>
     */
    private function orderForBacks(Collection $cards, array $requestedIds): Collection
    {
        return collect($requestedIds)
            ->map(fn (int $id) => $cards->get($id))
            ->filter()
            ->sortByDesc(fn (CredentialPrint $card) => $card->front_printed_at?->timestamp ?? $card->id)
            ->values();
    }

    /**
     * @param  Collection<int, CredentialPrint>  $cards
     * @return Collection<int, CredentialPrint>
     */
    private function cardsWithRelations(Collection $cards): Collection
    {
        if ($cards->isEmpty()) {
            return $cards;
        }

        $cards->load([
            'student.profile:id,first_name,last_name',
            'cardDesign:id,uuid,name,faces_mode',
            'creator:id,name',
            'discarder:id,name',
            'printJobs.creator:id,name',
            'printJobs.canceller:id,name',
            'printJobs.student.profile:id,first_name,last_name',
            'printJobs.credentialPrint:id,uuid',
        ]);

        return $cards;
    }
}
