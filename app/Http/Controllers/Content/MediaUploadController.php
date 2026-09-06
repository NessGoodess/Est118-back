<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\StoreLegalPdfUploadRequest;
use App\Http\Requests\Content\StoreMediaUploadRequest;
use App\Services\Media\PublicMediaStorageService;
use Illuminate\Http\JsonResponse;

/**
 * Batch image uploads for editor content (announcement gallery blocks,
 * gallery albums, event covers).
 */
class MediaUploadController extends Controller
{
    public function __construct(private readonly PublicMediaStorageService $media)
    {
    }

    /**
     * POST /api/content/media
     * Stores every uploaded image and returns their public URLs in order.
     */
    public function store(StoreMediaUploadRequest $request): JsonResponse
    {
        $collection = $request->collection();

        $maxWidth = $collection === PublicMediaStorageService::IDENTITY_BANNERS_DIR
            ? PublicMediaStorageService::IDENTITY_BANNER_MAX_WIDTH
            : null;

        $uploaded = collect($request->file('files'))
            ->map(function ($file) use ($collection, $maxWidth): array {
                $path = $this->media->storeImage($file, $collection, $maxWidth);

                return [
                    'path' => $path,
                    'src' => $this->media->publicPath($path),
                    'name' => $file->getClientOriginalName(),
                ];
            })
            ->values();

        return response()->json(['files' => $uploaded], 201);
    }

    /**
     * POST /api/content/pdf
     * Stores a single PDF for legal documents.
     */
    public function storePdf(StoreLegalPdfUploadRequest $request): JsonResponse
    {
        $file = $request->file('file');
        $path = $this->media->storePdf($file, PublicMediaStorageService::LEGAL_DOCUMENTS_DIR);

        return response()->json([
            'path' => $path,
            'src' => $this->media->publicPath($path),
            'name' => $file->getClientOriginalName(),
        ], 201);
    }
}
