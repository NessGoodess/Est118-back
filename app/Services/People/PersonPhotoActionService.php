<?php

namespace App\Services\People;

use App\Enums\PersonPhotoKind;
use App\Models\Profile;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class PersonPhotoActionService
{
    public function __construct(
        private readonly PersonPhotoService $photos,
        private readonly PersonPhotoPathService $paths,
    ) {}

    public function upload(Request $request, PersonPhotoKind $kind, int $id, Profile $profile): JsonResponse
    {
        $validated = $request->validate([
            'photo' => ['required', 'file', 'image', 'max:8192'],
            'upload_id' => ['nullable', 'uuid'],
        ]);

        $uploadId = $validated['upload_id'] ?? null;
        if (! is_string($uploadId)) {
            return response()->json($this->storeUploadedPhoto($request, $kind, $id, $profile));
        }

        $cacheKey = "person-photo-upload:{$kind->value}:{$id}:{$uploadId}";
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return response()->json($cached);
        }

        try {
            $body = Cache::lock("person-photo:{$kind->value}:{$id}", 60)->block(15, function () use ($request, $kind, $id, $profile, $cacheKey): array {
                $cached = Cache::get($cacheKey);
                if (is_array($cached)) {
                    return $cached;
                }

                $body = $this->storeUploadedPhoto($request, $kind, $id, $profile);
                Cache::put($cacheKey, $body, now()->addMinutes(30));

                return $body;
            });
        } catch (LockTimeoutException) {
            return response()->json([
                'success' => false,
                'message' => 'La foto se está guardando. Espera un momento e inténtalo de nuevo.',
            ], 409);
        }

        return response()->json($body);
    }

    public function history(PersonPhotoKind $kind, int $id): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->paths->historyFor($kind, $id),
        ]);
    }

    /**
     * @return array{success: bool, message: string, data: array<string, mixed>}
     */
    private function storeUploadedPhoto(Request $request, PersonPhotoKind $kind, int $id, Profile $profile): array
    {
        $result = $this->photos->store($kind, $id, $profile, $request->file('photo'));
        $urls = $this->paths->currentUrls($kind, $id);

        return [
            'success' => true,
            'message' => 'Foto guardada y optimizada correctamente.',
            'data' => [
                ...$result,
                'photo_url' => $urls['profile_url'],
                'photos' => $urls,
            ],
        ];
    }
}
