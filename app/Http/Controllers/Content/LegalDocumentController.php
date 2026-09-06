<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\UpsertLegalDocumentRequest;
use App\Models\Content\LegalDocument;
use App\Services\Media\PublicMediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LegalDocumentController extends Controller
{
    public function __construct(private readonly PublicMediaStorageService $media)
    {
    }

    /**
     * GET /legal-documents
     * Public: published privacy + school_rules. Manage: all three types.
     */
    public function index(Request $request): JsonResponse
    {
        if ($this->isManager($request)) {
            $rows = LegalDocument::query()->get()->keyBy('type');

            $payload = collect(LegalDocument::TYPES)->map(function (string $type) use ($rows) {
                return $rows->get($type) ?? [
                    'id' => null,
                    'type' => $type,
                    'title' => LegalDocument::defaultTitle($type),
                    'src' => null,
                    'original_name' => null,
                    'published_at' => null,
                ];
            });

            return response()->json($payload);
        }

        $documents = LegalDocument::query()
            ->whereIn('type', LegalDocument::PUBLIC_TYPES)
            ->published()
            ->get();

        return response()->json($documents);
    }

    /**
     * GET /legal-documents/{type}
     * student_photos is never public (404 without manage).
     */
    public function show(Request $request, string $type): JsonResponse
    {
        if (! in_array($type, LegalDocument::TYPES, true)) {
            abort(404);
        }

        $isManager = $this->isManager($request);

        if ($type === LegalDocument::TYPE_STUDENT_PHOTOS && ! $isManager) {
            abort(404);
        }

        $document = LegalDocument::query()->where('type', $type)->first();

        if (! $document) {
            if ($isManager) {
                return response()->json([
                    'id' => null,
                    'type' => $type,
                    'title' => LegalDocument::defaultTitle($type),
                    'src' => null,
                    'original_name' => null,
                    'published_at' => null,
                ]);
            }

            abort(404);
        }

        if (! $isManager && ($document->published_at === null || $document->published_at->isFuture())) {
            abort(404);
        }

        return response()->json($document);
    }

    /**
     * GET /legal-documents/{type}/file
     * Streams the PDF so the browser can save it without leaving the page.
     */
    public function file(Request $request, string $type): StreamedResponse
    {
        $document = $this->visibleDocument($request, $type);

        if (! $document->src) {
            abort(404);
        }

        $path = $this->media->relativePath($document->src);
        if ($path === null || ! Storage::disk(PublicMediaStorageService::DISK)->exists($path)) {
            abort(404);
        }

        $name = $document->original_name ?: ($document->title.'.pdf');

        return Storage::disk(PublicMediaStorageService::DISK)->download($path, $name, [
            'Content-Type' => 'application/pdf',
        ]);
    }

    public function upsert(UpsertLegalDocumentRequest $request, string $type): JsonResponse
    {
        $type = $request->documentType();
        $validated = $request->validated();

        $document = LegalDocument::query()->firstOrNew(['type' => $type]);
        $previousSrc = $document->src;

        $document->title = $validated['title'];
        $document->original_name = $validated['original_name'] ?? $document->original_name;
        $document->updated_by = $request->user()?->id;

        if (array_key_exists('src', $validated) && filled($validated['src'])) {
            $document->src = $validated['src'];
        }

        if ($request->has('publish_action')) {
            $document->published_at = $this->resolvePublishedAt(
                $request,
                $validated['published_at'] ?? $document->published_at?->toIso8601String()
            );
        }

        $document->save();

        if (
            filled($previousSrc)
            && filled($document->src)
            && $document->src !== $previousSrc
        ) {
            $this->media->delete($previousSrc);
        }

        return response()->json($document->fresh());
    }

    private function visibleDocument(Request $request, string $type): LegalDocument
    {
        if (! in_array($type, LegalDocument::TYPES, true)) {
            abort(404);
        }

        $isManager = $this->isManager($request);

        if ($type === LegalDocument::TYPE_STUDENT_PHOTOS && ! $isManager) {
            abort(404);
        }

        $document = LegalDocument::query()->where('type', $type)->first();

        if (! $document || ! $document->src) {
            abort(404);
        }

        if (! $isManager && ($document->published_at === null || $document->published_at->isFuture())) {
            abort(404);
        }

        return $document;
    }

    private function isManager(Request $request): bool
    {
        return $request->boolean('manage')
            && (bool) $request->user('sanctum')?->can('create legal documents');
    }

    private function resolvePublishedAt(Request $request, ?string $publishedAt): ?Carbon
    {
        $action = $request->input('publish_action', 'publish');

        if ($action === 'draft') {
            return null;
        }

        return now();
    }
}
