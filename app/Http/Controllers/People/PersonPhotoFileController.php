<?php

namespace App\Http\Controllers\People;

use App\Enums\PersonPhotoKind;
use App\Http\Controllers\Controller;
use App\Services\People\PersonPhotoPathService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

class PersonPhotoFileController extends Controller
{
    public function __construct(
        private readonly PersonPhotoPathService $paths,
    ) {}

    public function show(Request $request, string $kind, int $id): Response
    {
        $photoKind = PersonPhotoKind::tryFrom($kind);
        if ($photoKind === null) {
            return response()->json(['success' => false, 'message' => 'kind not found'], 404);
        }

        $size = $request->query('size', 'profile');
        $size = is_string($size) && in_array($size, ['thumb', 'profile', 'original'], true) ? $size : 'profile';
        $version = $request->query('version');

        try {
            if (is_string($version) && $version !== '') {
                if (! $this->paths->isHistoryVersion($version)) {
                    return response()->json(['success' => false, 'message' => 'version not found'], 404);
                }
                $path = $this->paths->resolveHistoryPath($photoKind, $id, $version, $size)
                    ?: $this->paths->resolveHistoryPath($photoKind, $id, $version, 'original');
            } else {
                $path = $this->paths->resolvePath($photoKind, $id, $size)
                    ?: $this->paths->resolvePath($photoKind, $id, 'original');
            }

            if (! $path || ! Storage::disk('private')->exists($path)) {
                return response()->noContent();
            }

            return Storage::disk('private')->response($path, null, [
                'Cache-Control' => 'private, max-age=86400, immutable',
            ]);
        } catch (\Throwable $e) {
            Log::error('Error getting person image', [
                'error' => $e->getMessage(),
                'kind' => $kind,
                'id' => $id,
            ]);

            return response()->json(['success' => false, 'message' => 'internal server error'], 500);
        }
    }
}
