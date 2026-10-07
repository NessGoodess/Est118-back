<?php

namespace App\Services\People;

use App\Enums\PersonPhotoKind;
use App\Models\Profile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

class PersonPhotoService
{
    public function __construct(
        private readonly PersonPhotoPathService $paths,
    ) {}

    /**
     * @return array{filename: string, path_original: string, path_profile: string, path_thumb: string}
     */
    public function store(PersonPhotoKind $kind, int $id, Profile $profile, UploadedFile $photo): array
    {
        $fullOriginalPath = null;

        try {
            $disk = Storage::disk('private');
            $currentDir = $this->paths->currentDirectory($kind, $id);

            $ext = strtolower($photo->getClientOriginalExtension() ?: 'jpg');
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $ext = 'jpg';
            }
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }

            $this->archiveCurrentIfPresent($kind, $id);
            $disk->makeDirectory($currentDir);

            $originalName = "original.{$ext}";
            $fullOriginalPath = "{$currentDir}/{$originalName}";
            $disk->putFileAs($currentDir, $photo, $originalName);

            $this->normalizeOriginalIfNeeded($fullOriginalPath, $ext);
            $this->writeVariantsForOriginal($fullOriginalPath);

            $dbValue = $this->paths->profilePictureValue($kind, $id);
            $profile->update(['profile_picture' => $dbValue]);

            return [
                'filename' => $dbValue,
                'path_original' => $fullOriginalPath,
                'path_profile' => "{$currentDir}/profile.jpg",
                'path_thumb' => "{$currentDir}/thumb.jpg",
            ];
        } catch (Throwable $e) {
            Log::error('[foto-persona] No se pudo guardar o convertir la foto', [
                'kind' => $kind->value,
                'id' => $id,
                'path' => $fullOriginalPath,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    public function normalizeOriginalIfNeeded(string $relativePath, ?string $ext = null): bool
    {
        $disk = Storage::disk('private');
        if (! $disk->exists($relativePath)) {
            return false;
        }

        $absolute = $disk->path($relativePath);
        $orientation = $this->readExifOrientation($absolute);
        if ($orientation <= 1) {
            return false;
        }

        $ext = strtolower($ext ?: pathinfo($relativePath, PATHINFO_EXTENSION) ?: 'jpg');
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        $manager = new ImageManager(new Driver(), autoOrientation: true, strip: true);
        $image = $manager->read($absolute);
        $disk->put($relativePath, (string) $this->encodeWithoutExif($image, $ext));

        return true;
    }

    public function writeVariantsForOriginal(string $originalRelative): void
    {
        $disk = Storage::disk('private');
        $absolute = $disk->path($originalRelative);
        $dir = str_replace('\\', '/', dirname($originalRelative));

        $manager = new ImageManager(new Driver());
        $disk->put("{$dir}/thumb.jpg", (string) $manager->read($absolute)->cover(40, 40)->toJpeg(75));
        $disk->put("{$dir}/profile.jpg", (string) $manager->read($absolute)->scale(width: 400)->toJpeg(80));
    }

    public function readExifOrientation(string $absolutePath): int
    {
        if (function_exists('exif_read_data')) {
            $exif = @exif_read_data($absolutePath);
            if (is_array($exif)) {
                $value = $exif['Orientation'] ?? ($exif['IFD0']['Orientation'] ?? null);
                if ($value !== null) {
                    return max(1, (int) $value);
                }
            }
        }

        $manager = new ImageManager(new Driver(), autoOrientation: false);
        $image = $manager->read($absolutePath);
        $value = $image->exif('IFD0.Orientation') ?? $image->exif('Orientation');

        return max(1, (int) ($value ?? 1));
    }

    private function encodeWithoutExif(ImageInterface $image, string $ext): string
    {
        return (string) match ($ext) {
            'png' => $image->toPng(),
            'webp' => $image->toWebp(90),
            default => $image->toJpeg(92),
        };
    }

    private function archiveCurrentIfPresent(PersonPhotoKind $kind, int $id): void
    {
        $disk = Storage::disk('private');
        $currentDir = $this->paths->currentDirectory($kind, $id);

        if (! $disk->exists($currentDir)) {
            return;
        }

        $files = $disk->files($currentDir);
        if ($files === []) {
            return;
        }

        $versionDir = $this->paths->versionsDirectory($kind, $id).'/'.now()->format('YmdHis');
        $disk->makeDirectory($versionDir);

        foreach ($files as $file) {
            $disk->move($file, $versionDir.'/'.basename($file));
        }
    }
}
