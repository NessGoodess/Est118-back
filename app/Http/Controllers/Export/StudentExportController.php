<?php

namespace App\Http\Controllers\Export;

use App\Http\Controllers\Controller;
use App\Http\Requests\Export\ExportCsvRequest;
use App\Http\Requests\Export\ExportStudentRowsRequest;
use App\Http\Requests\Export\FillExportTemplateRequest;
use App\Models\ExportTemplate;
use App\Services\Export\FillExportTemplateService;
use App\Services\Export\StudentExportRowService;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class StudentExportController extends Controller
{
    public function __construct(
        private readonly FillExportTemplateService $fillExportTemplateService,
        private readonly StudentExportRowService $studentExportRowService
    ) {}

    public function exportRows(ExportStudentRowsRequest $request): JsonResponse
    {
        $rows = $this->studentExportRowService->rowsForIds($request->validated()['ids']);

        return response()->json([
            'success' => true,
            'data' => $rows,
        ]);
    }

    public function exportWithTemplate(FillExportTemplateRequest $request): StreamedResponse|JsonResponse
    {
        $validated = $request->validated();
        $template = ExportTemplate::query()->findOrFail($validated['template_id']);

        if ($template->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado.',
            ], 403);
        }

        try {
            return $this->fillExportTemplateService->fillAndDownload(
                $template,
                $validated['columns'],
                $validated['cell_map'],
                $validated['rows'],
                'estudiantes_'.date('Y-m-d').'.xlsx'
            );
        } catch (\RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }
    }

    public function exportCsv(ExportCsvRequest $request): StreamedResponse
    {
        $validated = $request->validated();

        return $this->fillExportTemplateService->csvDownload(
            $validated['columns'],
            $validated['rows'],
            'estudiantes_'.date('Y-m-d').'.csv'
        );
    }
}
