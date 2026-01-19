<?php

namespace App\Http\Controllers;

use App\Enums\PreEnrollmentStatus;
use App\Models\PreEnrollment;
use App\Http\Requests\StorePreEnrollmentRequest;
use App\Http\Requests\UpdatePreEnrollmentRequest;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Storage;
use Barryvdh\DomPDF\Facade\Pdf;

class PreEnrollmentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        return PreEnrollment::all()
            ->orderBy('id', 'desc');
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StorePreEnrollmentRequest $request)
    {

        try {

            $preEnrollment = PreEnrollment::create([
                'contact_email' => $request->email['contactEmail'],
                'first_name' => $request->applicantInfo['firstName'],
                'last_name' => $request->applicantInfo['lastName'],
                'second_last_name' => $request->applicantInfo['secondLastName'],
                'curp' => $request->applicantInfo['curp'],
                'birth_date' => $request->applicantInfo['birthDate'],
                'age' => $request->applicantInfo['age'],
                'gender' => $request->applicantInfo['gender'],
                'phone' => $request->applicantInfo['phone'],
                'student_email' => $request->applicantInfo['studentEmail'],
                'place_of_birth' => $request->applicantInfo['placeOfBirth'],
                'previous_school' => $request->academicInfo['previousSchool'],
                'current_average' => $request->academicInfo['currentAverage'],
                'has_siblings' => $request->academicInfo['hasSiblings'],
                'siblings_details' => $request->academicInfo['siblingsDetails'],
                'street_type' => $request->addressInfo['streetType'],
                'street_name' => $request->addressInfo['streetName'],
                'house_number' => $request->addressInfo['houseNumber'],
                'unit_number' => $request->addressInfo['unitNumber'],
                'neighborhood_type' => $request->addressInfo['neighborhoodType'],
                'neighborhood_name' => $request->addressInfo['neighborhoodName'],
                'postal_code' => $request->addressInfo['postalCode'],
                'city' => $request->addressInfo['city'],
                'state' => $request->addressInfo['state'],
                'guardian_first_name' => $request->guardianInfo['guardianFirstName'],
                'guardian_last_name' => $request->guardianInfo['guardianLastName'],
                'guardian_second_last_name' => $request->guardianInfo['guardianSecondLastName'],
                'guardian_curp' => $request->guardianInfo['guardianCurp'],
                'guardian_phone' => $request->guardianInfo['guardianPhone'],
                'guardian_relationship' => $request->guardianInfo['guardianRelationship'],
                'workshop_first_choice' => $request->workshopSelect['workshopFirstChoice'],
                'workshop_second_choice' => $request->workshopSelect['workshopSecondChoice'],
                'has_school_voucher' => $request->tuitionVoucher['hasSchoolVoucher'],
                'school_voucher_folio' => $request->tuitionVoucher['hasSchoolVoucher']
                    ? $request->tuitionVoucher['schoolVoucherFolio']
                    : '0',
            ]);

            $pdf = Pdf::loadView('pdf.admission', [
                'folio' => $preEnrollment->folio,
                'data' => (object)[
                    'studentName' => $preEnrollment->first_name . ' ' . $preEnrollment->last_name,
                    'guardianName' => $preEnrollment->guardian_first_name . ' ' . $preEnrollment->guardian_last_name,
                    'createdAt' => $preEnrollment->created_at->format('d/m/Y H:i'),
                ]
            ])->setPaper('letter');

            // Guardar PDF en storage privado
            $pdfPath = "pdf/admission/{$preEnrollment->folio}.pdf";
            //Storage::put($pdfPath, $pdf->output());
            Storage::disk('private')->put($pdfPath, $pdf->output());

            // Generar URL temporal 5 minutos
            $signedUrl = URL::temporarySignedRoute(
                'folio.pdf', // el nombre de tu ruta
                now()->addMinutes(5),
                ['folio' => $preEnrollment->folio]
            );

            // Enviar correo con PDF adjunto
            //Mail::to($preEnrollment->contact_email)
            //  ->send(new PreEnrollmentConfirmedMail($preEnrollment, $pdfPath));

            return response()->json([
                "folio" => $preEnrollment->folio,
                "downloadUrl" => $signedUrl,
                "message" => "PreEnrollment created successfully"
            ], 201);
        } catch (\Exception $e) {
            Log::error('Error al crear preinscripción', [
                'message' => $e->getMessage()
            ]);
            return response()->json([
                "message" => "Ocurrió un error al procesar su preinscripción. Intente nuevamente más tarde." . $e->getMessage()
            ], 500);
        }
    }

    public function downloadPdf($folio)
    {
        $preEnrollment = PreEnrollment::where('folio', $folio)->firstOrFail();
        $pdfPath = "pdf/admission/{$preEnrollment->folio}.pdf";

        if (!Storage::disk('private')->exists($pdfPath)) {
            return response()->json(["message" => "PDF not found"], 404);
        }

        return response()->file(
            Storage::disk('private')->path($pdfPath),
            [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename=\"{$preEnrollment->folio}.pdf\"',
            ]
        );
    }

    /**
     * Display the specified resource.
     */
    public function show(PreEnrollment $preEnrollment)
    {
        return $preEnrollment;
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(PreEnrollment $preEnrollment)
    {
        //
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
}
