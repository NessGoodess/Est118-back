<?php

namespace App\Http\Controllers\Admission;

use App\Exceptions\AdmissionConversionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAdmissionConversionBatchRequest;
use App\Models\AdmissionConversionBatch;
use App\Services\AdmissionConversionBatchService;
use App\Services\AdmissionIdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class AdmissionConversionBatchController extends Controller implements HasMiddleware
{
    public function __construct(
        private AdmissionConversionBatchService $batchService,
        private AdmissionIdempotencyService $idempotencyService,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:edit admission enrollment'),
        ];
    }

    public function store(StoreAdmissionConversionBatchRequest $request): JsonResponse
    {
        return $this->idempotencyService->run(
            $request,
            AdmissionIdempotencyService::SCOPE_BATCH,
            function () use ($request) {
                try {
                    $batch = $this->batchService->createAndRun(
                        preEnrollmentIds: $request->validated('pre_enrollment_ids'),
                        academicYearId: (int) $request->validated('academic_year_id'),
                        userId: $request->user()?->id,
                        channel: (string) $request->input('channel', 'campaign'),
                        dryRun: (bool) $request->boolean('dry_run', false),
                        expectedCount: $request->filled('expected_count')
                            ? (int) $request->validated('expected_count')
                            : null,
                        idempotencyKey: $this->idempotencyService->extractKey($request),
                        requestHash: null,
                    );

                    return response()->json([
                        'success' => $batch->failed_count === 0,
                        'message' => $batch->dry_run
                            ? 'Simulación de lote completada.'
                            : ($batch->failed_count === 0
                                ? 'Lote de inscripción completado.'
                                : 'El lote terminó con observaciones.'),
                        'data' => $batch->toApiArray(),
                    ]);
                } catch (AdmissionConversionException $exception) {
                    return response()->json([
                        'success' => false,
                        'error_code' => $exception->errorCode,
                        'message' => $exception->getMessage(),
                    ], $exception->httpStatus);
                }
            }
        );
    }

    public function show(AdmissionConversionBatch $batch): JsonResponse
    {
        $batch->load(['items.preEnrollment']);

        return response()->json([
            'success' => true,
            'data' => $batch->toApiArray(),
        ]);
    }

    public function retryFailed(Request $request, AdmissionConversionBatch $batch): JsonResponse
    {
        try {
            $batch = $this->batchService->retryFailed($batch, $request->user()?->id);

            return response()->json([
                'success' => $batch->failed_count === 0,
                'message' => $batch->failed_count === 0
                    ? 'Reintento de fallidos completado.'
                    : 'El reintento terminó con observaciones.',
                'data' => $batch->toApiArray(),
            ]);
        } catch (AdmissionConversionException $exception) {
            return response()->json([
                'success' => false,
                'error_code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ], $exception->httpStatus);
        }
    }
}
