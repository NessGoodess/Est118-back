<?php

namespace App\Services\Media;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

/**
 * Shared storage for publicly served media (announcements, galleries, events, identity banners).
 *
 * Images are re-encoded to WebP and downscaled; videos are stored untouched.
 * Store methods return the relative disk path; callers decide whether to
 * persist the path or the absolute URL via url().
 */
class PublicMediaStorageService
{
    public const DISK = 'public';

    public const ANNOUNCEMENTS_DIR = 'announcements';
    public const GALLERIES_DIR = 'galleries';
    public const EVENTS_DIR = 'events';
    public const IDENTITY_BANNERS_DIR = 'identity-banners';

    private const IMG_MAX_WIDTH = 1280;

    public const IDENTITY_BANNER_MAX_WIDTH = 2400;

    private const IMG_QUALITY = 82;

    /**
     * Optimizes an upload to WebP and returns its relative disk path.
     */
    public function storeImage(UploadedFile $file, string $directory, ?int $maxWidth = null): string
    {
        $manager = new ImageManager(new Driver());
        $image = $manager->read($file->getRealPath());

        $limit = $maxWidth ?? self::IMG_MAX_WIDTH;
        if ($image->width() > $limit) {
            $image->scaleDown(width: $limit);
        }

        $path = $this->buildPath($directory, 'webp');
        Storage::disk(self::DISK)->put($path, $image->toWebp(self::IMG_QUALITY));

        return $path;
    }

    /**
     * Stores a video upload as-is and returns its relative disk path.
     */
    public function storeVideo(UploadedFile $file, string $directory): string
    {
        $extension = $file->getClientOriginalExtension() ?: 'mp4';
        $path = $this->buildPath($directory, $extension);

        Storage::disk(self::DISK)->put($path, file_get_contents($file->getRealPath()));

        return $path;
    }

    /**
     * Absolute public URL for a relative disk path.
     */
    public function url(string $path): string
    {
        return Storage::disk(self::DISK)->url($path);
    }

    /**
     * Resolves a relative disk path from either a stored path or a full public URL.
     * Returns null for external links that we do not own.
     */
    public function relativePath(?string $src): ?string
    {
        $value = trim((string) $src);
        if ($value === '') {
            return null;
        }

        if (! str_starts_with($value, 'http')) {
            return ltrim($value, '/');
        }

        $base = Storage::disk(self::DISK)->url('');
        if ($base !== '' && str_starts_with($value, $base)) {
            return ltrim(Str::after($value, $base), '/');
        }

        // Fall back to the conventional /storage/ prefix (symlinked public disk).
        $path = (string) parse_url($value, PHP_URL_PATH);
        if ($path !== '' && str_contains($path, '/storage/')) {
            return ltrim(Str::after($path, '/storage/'), '/');
        }

        return null;
    }

    /**
     * Deletes a locally stored file. Accepts a relative path or a public URL and
     * silently ignores external links.
     */
    public function delete(?string $src): void
    {
        $path = $this->relativePath($src);
        if ($path === null || $path === '') {
            return;
        }

        try {
            if (Storage::disk(self::DISK)->exists($path)) {
                Storage::disk(self::DISK)->delete($path);
            }
        } catch (\Throwable $e) {
            Log::warning('Could not delete public media file', [
                'src' => $src,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param  iterable<int, string|null>  $sources
     */
    public function deleteMany(iterable $sources): void
    {
        foreach ($sources as $src) {
            $this->delete($src);
        }
    }

    private function buildPath(string $directory, string $extension): string
    {
        return trim($directory, '/') . '/' . Str::uuid() . '.' . $extension;
    }
}
