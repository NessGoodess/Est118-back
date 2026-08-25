<?php

namespace App\Http\Controllers\Admission;

use App\Enums\AdmissionCycleStatus;
use App\Exceptions\AdmissionConversionException;
use App\Http\Controllers\Controller;
use App\Http\Requests\ConvertPreEnrollmentToStudentRequest;
use App\Http\Requests\InitialReviewPreEnrollmentRequest;
use App\Http\Requests\StorePreEnrollmentRequest;
use App\Http\Requests\UpdatePreEnrollmentProcessRequest;
use App\Http\Requests\UpdatePreEnrollmentRequest;
use App\Http\Resources\PreEnrollmentDetailResource;
use App\Http\Resources\PreEnrollmentListResource;
use App\Models\Admission\AdmissionCycle;
use App\Models\PreEnrollment;
use App\Services\AdmissionIdempotencyService;
use App\Services\ConvertPreEnrollmentToStudentService;
use App\Services\PreEnrollmentProcessService;
use App\Services\PreEnrollmentService;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Throwable;

class PreEnrollmentController extends Controller implements HasMiddleware
{
    public function __construct(
        private PreEnrollmentService $preEnrollmentService,
        private ConvertPreEnrollmentToStudentService $convertPreEnrollmentService,
        private AdmissionIdempotencyService $idempotencyService,
        private PreEnrollmentProcessService $processService,
    ) {}

    public static function middleware(): array
    {
        return [
            new Middleware('permission:view pre-enrollments')->only(['index', 'show']),
            new Middleware('permission:create pre-enrollments')->only(['storeByAdmin']),
            new Middleware('permission:edit pre-enrollments')->only(['update', 'resentPdfFolio']),
            new Middleware('permission:delete pre-enrollments')->only(['destroy']),
            new Middleware('permission:edit admission enrollment')->only([
                'updateProcess',
                'initialReview',
                'convertToStudent',
            ]),
        ];
    }

    /**
     * Display a listing of the resource.
     */
    public function index(\Illuminate\Http\Request $request)
    {
        $cycleId = $request->integer('cycle_id');

        // Si se envía cycle_id, úsalo, si no busca el ciclo activo
        if ($cycleId) {
            $cycle = AdmissionCycle::find($cycleId);
            if (! $cycle) {
                return response()->json([
                    'status' => 'Not Found',
                    'message' => 'Ciclo no encontrado',
                ], 404);
            }
            $activeCycle = $cycle;
        } else {
            $activeCycle = AdmissionCycle::where('status', AdmissionCycleStatus::ACTIVE)->first();

            if (! $activeCycle) {

                $latestCycle = AdmissionCycle::latest()->first();
                if (! $latestCycle) {
                    return response()->json([
                        'status' => 'Not Found',
                        'message' => __('admissions.no_active_cycle'),
                    ], 404);
                }

                $activeCycle = $latestCycle;
            }
        }

        return PreEnrollmentListResource::collection(
            PreEnrollment::where('admission_cycle_id', $activeCycle->id)
                ->orderByDesc('id')
                ->paginate(300)
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePreEnrollmentRequest $request)
    {
        return $this->idempotencyService->run(
            $request,
            AdmissionIdempotencyService::SCOPE_PUBLIC_STORE,
            function () use ($request) {
                try {
                    $result = $this->preEnrollmentService->createPreEnrollment($request->validated());

                    return response()->json([
                        'folio' => $result['folio'],
                        'downloadUrl' => $result['downloadUrl'],
                        'message' => __('admissions.created_success'),
                    ], 201);
                } catch (\Exception $e) {
                    Log::error('Error al crear preinscripción', [
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    return response()->json([
                        'message' => __('admissions.error_processing'),
                    ], 500);
                }
            }
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(PreEnrollment $preEnrollment)
    {
        return new PreEnrollmentDetailResource($preEnrollment);
    }

    /**
     * Store a newly created pre-enrollment from the Admin panel.
     */
    public function storeByAdmin(StorePreEnrollmentRequest $request)
    {
        return $this->idempotencyService->run(
            $request,
            AdmissionIdempotencyService::SCOPE_ADMIN_STORE,
            function () use ($request) {
                try {
                    $cycleId = $request->input('admission_cycle_id') ? (int) $request->input('admission_cycle_id') : null;
                    $result = $this->preEnrollmentService->createPreEnrollment(
                        $request->validated(),
                        $cycleId,
                        $request->user()
                    );

                    return response()->json([
                        'folio' => $result['folio'],
                        'downloadUrl' => $result['downloadUrl'],
                        'message' => __('admissions.created_success'),
                    ], 201);
                } catch (\Exception $e) {
                    Log::error('Error al crear preinscripción via Admin', [
                        'message' => $e->getMessage(),
                        'trace' => $e->getTraceAsString(),
                    ]);

                    return response()->json([
                        'message' => __('admissions.error_processing'),
                    ], 500);
                }
            }
        );
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePreEnrollmentRequest $request, PreEnrollment $preEnrollment)
    {
        if ($preEnrollment->converted_student_id) {
            return response()->json([
                'success' => false,
                'message' => 'Esta solicitud ya fue inscrita y no se puede modificar.',
            ], 422);
        }

        $preEnrollment->update($request->validated());

        return new PreEnrollmentDetailResource($preEnrollment->fresh());
    }

    /**
     * Start review: pending → in_review (audited).
     */
    public function initialReview(
        InitialReviewPreEnrollmentRequest $request,
        PreEnrollment $preEnrollment
    ) {
        try {
            $updated = $this->processService->startInitialReview($preEnrollment, [
                'expected_updated_at' => $request->input('expected_updated_at'),
                'notes' => $request->input('notes'),
                'documents_status' => $request->input('documents_status'),
                'payment_status' => $request->input('payment_status'),
                'admission_exam_score' => $request->input('admission_exam_score'),
                'reviewed_by' => $request->user()?->id,
            ]);

            return new PreEnrollmentDetailResource($updated);
        } catch (ValidationException $exception) {
            throw $exception;
        }
    }

    /**
     * Update only process fields (status, documents_status, payment_status).
     */
    public function updateProcess(UpdatePreEnrollmentProcessRequest $request, PreEnrollment $preEnrollment)
    {
        try {
            $updated = $this->processService->updateProcess(
                $preEnrollment,
                $request->validated(),
                $request->user()?->id,
            );

            return new PreEnrollmentDetailResource($updated);
        } catch (ValidationException $exception) {
            throw $exception;
        }
    }

    /**
     * Convert pre-enrollment to student + enrollment (1° provisional group).
     */
    public function convertToStudent(
        ConvertPreEnrollmentToStudentRequest $request,
        PreEnrollment $preEnrollment
    ) {
        return $this->idempotencyService->run(
            $request,
            AdmissionIdempotencyService::SCOPE_CONVERT,
            function () use ($request, $preEnrollment) {
                try {
                    $payload = $this->convertPreEnrollmentService->convert(
                        $preEnrollment,
                        [
                            'academic_year_id' => $request->filled('academic_year_id') ? $request->integer('academic_year_id') : null,
                            'class_group_id' => $request->filled('class_group_id') ? $request->integer('class_group_id') : null,
                            'channel' => $request->input('channel', 'campaign'),
                            'force_incomplete_docs' => $request->boolean('force_incomplete_docs'),
                            'force_incomplete_data' => $request->boolean('force_incomplete_data'),
                            'force_without_payment' => $request->boolean('force_without_payment'),
                            'converted_by' => $request->user()?->id,
                        ],
                    );

                    return response()->json([
                        'success' => true,
                        'message' => $payload['replayed']
                            ? __('admissions.to_student.replayed_success')
                            : __('admissions.to_student.converted_success'),
                        'replayed' => $payload['replayed'],
                        'data' => [
                            'student_id' => $payload['student']->id,
                            'enrollment_id' => $payload['enrollment']->id,
                            'replayed' => $payload['replayed'],
                            'exception_flags' => $payload['exception_flags'],
                            'admission_channel' => $payload['enrollment']->admission_channel,
                            'placement_status' => $payload['enrollment']->placement_status,
                        ],
                    ]);
                } catch (AdmissionConversionException $exception) {
                    if ($exception->httpStatus >= 500) {
                        Log::error('Admission conversion failed', [
                            'pre_enrollment_id' => $preEnrollment->id,
                            'error_code' => $exception->errorCode,
                            'message' => $exception->getPrevious()?->getMessage()
                                ?? $exception->getMessage(),
                        ]);
                    }

                    return response()->json([
                        'success' => false,
                        'error_code' => $exception->errorCode,
                        'message' => $exception->getMessage(),
                    ], $exception->httpStatus);
                } catch (Throwable $exception) {
                    Log::error('Unexpected admission conversion error', [
                        'pre_enrollment_id' => $preEnrollment->id,
                        'message' => $exception->getMessage(),
                    ]);

                    return response()->json([
                        'success' => false,
                        'error_code' => 'conversion_failed',
                        'message' => __('admissions.to_student.conversion_failed'),
                    ], 500);
                }
            }
        );
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(PreEnrollment $preEnrollment)
    {
        //
    }

    /**
     * Download the PDF for a pre-enrollment.
     */
    public function downloadPdf(string $folio)
    {
        $preEnrollment = PreEnrollment::where('folio', $folio)->firstOrFail();
        $pdfPath = "pdf/admission/{$preEnrollment->folio}.pdf";

        if (! Storage::disk('private')->exists($pdfPath)) {
            return response()->json(['message' => 'PDF not found'], 404);
        }

        return response()->file(
            Storage::disk('private')->path($pdfPath),
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => "inline; filename=\"{$preEnrollment->folio}.pdf\"",
            ]
        );
    }

    /**
     * Resent PDF Folio
     */
    public function resentPdfFolio(PreEnrollment $preEnrollment)
    {
        try {
            $pdf = $preEnrollment->folio;
            $pdfPath = "pdf/admission/{$pdf}.pdf";
            $this->preEnrollmentService->sendEmail($preEnrollment, $pdfPath);
        } catch (\Throwable $th) {
            Log::error('Error al reenviar PDF', [
                'message' => $th->getMessage(),
                'trace' => $th->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => 'Error al reenviar PDF',
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'message' => 'PDF resent successfully',
            'folio' => $preEnrollment->folio,

        ]);
    }
}
