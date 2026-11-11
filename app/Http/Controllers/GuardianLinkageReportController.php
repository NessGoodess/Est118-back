<?php

namespace App\Http\Controllers;

use App\Models\Guardian;
use App\Services\People\GuardianLinkageReportService;
use Barryvdh\DomPDF\Facade\Pdf;
use Symfony\Component\HttpFoundation\Response;

class GuardianLinkageReportController extends Controller
{
    public function __construct(
        private readonly GuardianLinkageReportService $report,
    ) {}

    public function pdf(Guardian $guardian): Response
    {
        $payload = $this->report->payloadForGuardian($guardian);
        $students = collect($payload['students'] ?? []);

        $pdf = Pdf::loadView('pdf.guardian-linkage', [
            'school' => config('app.school_name', config('app.name')),
            'tutor' => $payload,
            'primary' => $students->where('role', 'primary')->values()->all(),
            'secondary' => $students->where('role', 'secondary')->values()->all(),
            'linked' => $students->where('role', 'linked')->values()->all(),
            'generatedAt' => now()->timezone(config('app.timezone'))->format('d/m/Y H:i'),
        ])->setPaper('letter');

        $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($payload['name'] ?? 'tutor')) ?: 'tutor';

        return $pdf->download("reporte-tutor-{$safeName}.pdf");
    }
}
