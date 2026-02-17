<?php

namespace App\Services;

use App\Jobs\SendPreEnrollmentEmailJob;
use App\Models\Admission\AdmissionCycle;
use App\Models\PreEnrollment;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class PreEnrollmentService
{
    /**
     * Creates a new pre-enrollment with its PDF and schedules the email.
     *
     * @param  array  $data  Validated request data
     * @return array ['folio' => string, 'downloadUrl' => string]
     */
    public function createPreEnrollment(array $data): array
    {
        // 1. Create record in database
        $preEnrollment = $this->storePreEnrollment($data);

        // 2. Generate and store PDF (synchronous to have immediate download URL)
        $pdfPath = $this->generateAndStorePdf($preEnrollment);

        // 3. Temporal signed url for download pdf
        $signedUrl = $this->generateSignedUrl($preEnrollment->folio);

        // 4. Dispatch email to queue with delay for asynchronous processing
        $this->sendEmail($preEnrollment, $pdfPath);

        return [
            'folio' => $preEnrollment->folio,
            'downloadUrl' => $signedUrl,
        ];
    }

    /**
     * Creates the pre-enrollment record in the database.
     */
    private function storePreEnrollment(array $data): PreEnrollment
    {
        $cycle = AdmissionCycle::public()->firstOrFail();

        $data = $this->createData($data);

        return PreEnrollment::create([
            ...$data,
            'admission_cycle_id' => $cycle->id,
        ]);
    }

    /**
     * Generates the admission PDF and stores it in private storage.
     */
    private function generateAndStorePdf(PreEnrollment $preEnrollment): string
    {
        $logoBase64 = $this->imageToBase64(
            storage_path('app/public/images/Logo_EST118.png')
        );

        $pdf = Pdf::loadView('pdf.admission', [
            'folio' => $preEnrollment->folio,
            'logoBase64' => $logoBase64,
            'data' => (object) [
                'studentName' => "{$preEnrollment->first_name} {$preEnrollment->last_name}",
                'guardianName' => "{$preEnrollment->guardian_first_name} {$preEnrollment->guardian_last_name}",
                'createdAt' => $preEnrollment->created_at->format('d/m/Y H:i'),
            ],
        ])->setPaper('letter');

        $pdfPath = "pdf/admission/{$preEnrollment->folio}.pdf";
        Storage::disk('private')->put($pdfPath, $pdf->output());

        return $pdfPath;
    }

    /**
     * Generates a signed temporary URL to download the PDF.
     */
    private function generateSignedUrl(string $folio): string
    {
        return URL::temporarySignedRoute(
            'folio.pdf',
            now()->addMinutes(5),
            ['folio' => $folio]
        );
    }

    private function createData(array $data): array
    {
        return [
            'contact_email' => $data['email']['contactEmail'],
            'first_name' => $data['applicantInfo']['firstName'],
            'last_name' => $data['applicantInfo']['lastName'],
            'second_last_name' => $data['applicantInfo']['secondLastName'],
            'curp' => $data['applicantInfo']['curp'],
            'birth_date' => $data['applicantInfo']['birthDate'],
            'age' => $data['applicantInfo']['age'],
            'gender' => $data['applicantInfo']['gender'],
            'phone' => $data['applicantInfo']['phone'],
            'student_email' => $data['applicantInfo']['studentEmail'],
            'place_of_birth' => $data['applicantInfo']['placeOfBirth'],
            'previous_school' => $data['academicInfo']['previousSchool'],
            'current_average' => $data['academicInfo']['currentAverage'],
            'has_siblings' => $data['academicInfo']['hasSiblings'],
            'siblings_details' => $data['academicInfo']['siblingsDetails'],
            'street_type' => $data['addressInfo']['streetType'],
            'street_name' => $data['addressInfo']['streetName'],
            'house_number' => $data['addressInfo']['houseNumber'],
            'unit_number' => $data['addressInfo']['unitNumber'],
            'neighborhood_type' => $data['addressInfo']['neighborhoodType'],
            'neighborhood_name' => $data['addressInfo']['neighborhoodName'],
            'postal_code' => $data['addressInfo']['postalCode'],
            'city' => $data['addressInfo']['city'],
            'state' => $data['addressInfo']['state'],
            'guardian_first_name' => $data['guardianInfo']['guardianFirstName'],
            'guardian_last_name' => $data['guardianInfo']['guardianLastName'],
            'guardian_second_last_name' => $data['guardianInfo']['guardianSecondLastName'],
            'guardian_curp' => $data['guardianInfo']['guardianCurp'],
            'guardian_phone' => $data['guardianInfo']['guardianPhone'],
            'guardian_relationship' => $data['guardianInfo']['guardianRelationship'],
            'workshop_first_choice' => $data['workshopSelect']['workshopFirstChoice'],
            'workshop_second_choice' => $data['workshopSelect']['workshopSecondChoice'],
            'has_school_voucher' => $data['tuitionVoucher']['hasSchoolVoucher'],
            'school_voucher_folio' => $data['tuitionVoucher']['hasSchoolVoucher']
                ? $data['tuitionVoucher']['schoolVoucherFolio']
                : '0',
        ];
    }

    private function imageToBase64(string $path, string $mime = 'png'): string
    {
        if (! file_exists($path)) {
            throw new \RuntimeException("Imagen no encontrada: {$path}");
        }

        return "data:image/{$mime};base64," . base64_encode(file_get_contents($path));
    }

    public function sendEmail(PreEnrollment $preEnrollment, string $pdfPath): void
    {
        $counter = Cache::increment('email_delay_counter');
        $delaySeconds = min($counter * 20, 1800); // max 30 minutes
        SendPreEnrollmentEmailJob::dispatch($preEnrollment, $pdfPath)
            ->delay(now()->addSeconds($delaySeconds));
    }
}
