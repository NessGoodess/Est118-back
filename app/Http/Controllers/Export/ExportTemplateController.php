<?php

namespace App\Http\Controllers\Export;

use App\Http\Controllers\Controller;
use App\Http\Requests\Export\StoreExportTemplateRequest;
use App\Models\ExportTemplate;
use App\Services\Export\ExportTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ExportTemplateController extends Controller
{
    public function __construct(
        private readonly ExportTemplateService $exportTemplateService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $context = $request->query('context');
        $templates = $this->exportTemplateService->listForUser(
            $request->user(),
            is_string($context) && $context !== '' ? $context : null
        );

        return response()->json([
            'success' => true,
            'data' => $templates,
        ]);
    }

    public function store(StoreExportTemplateRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $template = $this->exportTemplateService->store(
            $request->user(),
            $request->file('file'),
            $validated['name'],
            $validated['context'],
            (bool) ($validated['is_default'] ?? false)
        );

        return response()->json([
            'success' => true,
            'message' => 'Plantilla guardada correctamente.',
            'data' => $template,
        ], 201);
    }

    public function setDefault(Request $request, ExportTemplate $exportTemplate): JsonResponse
    {
        if ($exportTemplate->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado.',
            ], 403);
        }

        $template = $this->exportTemplateService->setDefault($exportTemplate);

        return response()->json([
            'success' => true,
            'message' => 'Plantilla marcada como predeterminada.',
            'data' => $template,
        ]);
    }

    public function destroy(Request $request, ExportTemplate $exportTemplate): JsonResponse
    {
        if ($exportTemplate->user_id !== $request->user()->id) {
            return response()->json([
                'success' => false,
                'message' => 'No autorizado.',
            ], 403);
        }

        $this->exportTemplateService->delete($exportTemplate);

        return response()->json([
            'success' => true,
            'message' => 'Plantilla eliminada.',
        ]);
    }
}
