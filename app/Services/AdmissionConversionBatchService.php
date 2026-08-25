<?php

namespace App\Services;

use App\Exceptions\AdmissionConversionException;
use App\Models\AdmissionConversionBatch;
use App\Models\AdmissionConversionBatchItem;
use App\Models\AdmissionIntakeSetting;
use App\Models\PreEnrollment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class AdmissionConversionBatchService
{
    public function __construct(
        private ConvertPreEnrollmentToStudentService $convertService
    ) {}

    /**
     * @param  list<int>  $preEnrollmentIds
     * @return AdmissionConversionBatch
     */
    public function createAndRun(
        array $preEnrollmentIds,
        int $academicYearId,
        ?int $userId = null,
        string $channel = 'campaign',
        bool $dryRun = false,
        ?int $expectedCount = null,
        ?string $idempotencyKey = null,
        ?string $requestHash = null,
    ): AdmissionConversionBatch {
        $ids = array_values(array_unique(array_map('intval', $preEnrollmentIds)));

        if ($expectedCount !== null && $expectedCount !== count($ids)) {
            throw new AdmissionConversionException(
                'expected_count_mismatch',
                "Se esperaban {$expectedCount} solicitudes, pero se recibieron ".count($ids).'.',
                422,
            );
        }

        $batch = DB::transaction(function () use (
            $ids,
            $academicYearId,
            $userId,
            $channel,
            $dryRun,
            $expectedCount,
            $idempotencyKey,
            $requestHash,
        ) {
            $batch = AdmissionConversionBatch::query()->create([
                'user_id' => $userId,
                'academic_year_id' => $academicYearId,
                'channel' => $channel === 'late' ? 'late' : 'campaign',
                'status' => 'running',
                'dry_run' => $dryRun,
                'expected_count' => $expectedCount,
                'requested_count' => count($ids),
                'policy_snapshot' => AdmissionIntakeSetting::current()->toApiArray(),
                'idempotency_key' => $idempotencyKey,
                'request_hash' => $requestHash,
            ]);

            foreach ($ids as $preEnrollmentId) {
                AdmissionConversionBatchItem::query()->create([
                    'batch_id' => $batch->id,
                    'pre_enrollment_id' => $preEnrollmentId,
                    'status' => 'pending',
                ]);
            }

            return $batch;
        });

        if ($dryRun) {
            $this->markDryRunPreview($batch);

            return $batch->fresh(['items.preEnrollment']);
        }

        $this->processItems($batch, onlyFailed: false);

        return $batch->fresh(['items.preEnrollment']);
    }

    public function retryFailed(AdmissionConversionBatch $batch, ?int $userId = null): AdmissionConversionBatch
    {
        if ($batch->dry_run) {
            throw new AdmissionConversionException(
                'dry_run_cannot_retry',
                'No se puede reintentar un lote en modo simulación.',
                422,
            );
        }

        $failedCount = $batch->items()->where('status', 'failed')->count();
        if ($failedCount === 0) {
            return $batch->load(['items.preEnrollment']);
        }

        $batch->update([
            'status' => 'running',
            'user_id' => $userId ?? $batch->user_id,
        ]);

        $this->processItems($batch, onlyFailed: true);

        return $batch->fresh(['items.preEnrollment']);
    }

    private function markDryRunPreview(AdmissionConversionBatch $batch): void
    {
        $converted = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($batch->items as $item) {
            $pre = PreEnrollment::find($item->pre_enrollment_id);
            if (! $pre) {
                $failed++;
                $item->update([
                    'status' => 'failed',
                    'message' => 'La solicitud ya no existe.',
                    'error_code' => 'pre_enrollment_not_found',
                ]);

                continue;
            }

            if ($pre->converted_student_id) {
                $skipped++;
                $item->update([
                    'status' => 'skipped',
                    'student_id' => $pre->converted_student_id,
                    'enrollment_id' => $pre->converted_enrollment_id,
                    'message' => 'La solicitud ya estaba inscrita.',
                ]);

                continue;
            }

            $converted++;
            $item->update([
                'status' => 'converted',
                'message' => 'Simulación: se inscribiría.',
            ]);
        }

        $batch->update([
            'status' => 'completed',
            'converted_count' => $converted,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
        ]);
    }

    private function processItems(AdmissionConversionBatch $batch, bool $onlyFailed): void
    {
        $query = $batch->items()->orderBy('id');
        if ($onlyFailed) {
            $query->where('status', 'failed');
        } else {
            $query->where('status', 'pending');
        }

        $items = $query->get();

        foreach ($items as $item) {
            $this->processOne($batch, $item);
        }

        $this->refreshCounters($batch);
    }

    private function processOne(AdmissionConversionBatch $batch, AdmissionConversionBatchItem $item): void
    {
        $preEnrollment = PreEnrollment::find($item->pre_enrollment_id);

        if (! $preEnrollment) {
            $item->update([
                'status' => 'failed',
                'error_code' => 'pre_enrollment_not_found',
                'message' => 'La solicitud ya no existe.',
                'student_id' => null,
                'enrollment_id' => null,
                'exception_flags' => null,
            ]);

            return;
        }

        if ($preEnrollment->converted_student_id) {
            $item->update([
                'status' => 'skipped',
                'student_id' => $preEnrollment->converted_student_id,
                'enrollment_id' => $preEnrollment->converted_enrollment_id,
                'message' => 'La solicitud ya estaba inscrita.',
                'error_code' => null,
                'exception_flags' => null,
            ]);

            return;
        }

        try {
            $payload = $this->convertService->convert($preEnrollment, [
                'academic_year_id' => $batch->academic_year_id,
                'channel' => $batch->channel,
                'converted_by' => $batch->user_id,
            ]);

            $wasReplayed = $payload['replayed'];
            $item->update([
                'status' => $wasReplayed ? 'skipped' : 'converted',
                'student_id' => $payload['student']->id,
                'enrollment_id' => $payload['enrollment']->id,
                'message' => $wasReplayed
                    ? 'La solicitud ya estaba inscrita.'
                    : 'Inscripción creada.',
                'error_code' => null,
                'exception_flags' => $payload['exception_flags'] ?: null,
            ]);
        } catch (AdmissionConversionException $exception) {
            $item->update([
                'status' => 'failed',
                'error_code' => $exception->errorCode,
                'message' => $exception->getMessage(),
                'student_id' => null,
                'enrollment_id' => null,
                'exception_flags' => null,
            ]);
        } catch (Throwable $exception) {
            Log::error('Unexpected admission conversion batch item error', [
                'batch_id' => $batch->id,
                'pre_enrollment_id' => $item->pre_enrollment_id,
                'message' => $exception->getMessage(),
            ]);
            $item->update([
                'status' => 'failed',
                'error_code' => 'conversion_failed',
                'message' => 'Ocurrió un error inesperado al crear la inscripción.',
                'student_id' => null,
                'enrollment_id' => null,
                'exception_flags' => null,
            ]);
        }
    }

    private function refreshCounters(AdmissionConversionBatch $batch): void
    {
        $batch->refresh();
        $converted = $batch->items()->where('status', 'converted')->count();
        $skipped = $batch->items()->where('status', 'skipped')->count();
        $failed = $batch->items()->where('status', 'failed')->count();
        $pending = $batch->items()->where('status', 'pending')->count();

        $batch->update([
            'converted_count' => $converted,
            'skipped_count' => $skipped,
            'failed_count' => $failed,
            'status' => $pending > 0 ? 'running' : 'completed',
        ]);
    }
}
