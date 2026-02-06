<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePreEnrollmentRequest;
use App\Http\Requests\UpdatePreEnrollmentRequest;
use App\Http\Resources\PreEnrollmentListResource;
use App\Models\Admission\AdmissionCycle;
use App\Models\PreEnrollment;
use App\Services\PreEnrollmentService;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PreEnrollmentController extends Controller
{
    public function __construct(
        private PreEnrollmentService $preEnrollmentService
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $latestCycle = AdmissionCycle::latest()->first();
        return PreEnrollmentListResource::collection(
            PreEnrollment::where('admission_cycle_id', $latestCycle->id)
                ->orderByDesc('id')
                ->paginate(100)
        );
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePreEnrollmentRequest $request)
    {
        try {
            $result = $this->preEnrollmentService->createPreEnrollment($request->validated());

            return response()->json([
                'folio' => $result['folio'],
                'downloadUrl' => $result['downloadUrl'],
                'message' => 'Preinscripción creada exitosamente',
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al crear preinscripción', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Ocurrió un error al procesar su preinscripción. Intente nuevamente más tarde.',
            ], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(PreEnrollment $preEnrollment)
    {
        return $preEnrollment;
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(UpdatePreEnrollmentRequest $request, PreEnrollment $preEnrollment)
    {
        //
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
}
