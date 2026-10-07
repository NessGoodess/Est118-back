<?php

namespace App\Services\People;

use App\Enums\PersonPhotoKind;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class PersonPhotoPathService
{
    public function currentDirectory(PersonPhotoKind $kind, int $id): string
    {
        return "photos/{$kind->value}/{$id}/current";
    }

    public function versionsDirectory(PersonPhotoKind $kind, int $id): string
    {
        return "photos/{$kind->value}/{$id}/versions";
    }

    public function profilePictureValue(PersonPhotoKind $kind, int $id): string
    {
        return "{$kind->value}/{$id}/current/profile.jpg";
    }

    public function isHistoryVersion(string $version): bool
    {
        return $version === 'current' || preg_match('/^\d{14}$/', $version) === 1;
    }

    public function fileExists(PersonPhotoKind $kind, int $id, string $size = 'profile'): bool
    {
        $path = $this->resolvePath($kind, $id, $size);

        return $path !== null && Storage::disk('private')->exists($path);
    }

    public function resolvePath(PersonPhotoKind $kind, int $id, string $size = 'profile'): ?string
    {
        return $this->resolveHistoryPath($kind, $id, 'current', $size);
    }

    public function resolveHistoryPath(PersonPhotoKind $kind, int $id, string $version, string $size): ?string
    {
        $dir = $this->historyDirectory($kind, $id, $version);
        if ($dir === null) {
            return null;
        }

        $size = $this->normalizeSize($size);
        $disk = Storage::disk('private');
        $preferred = match ($size) {
            'thumb' => "{$dir}/thumb.jpg",
            'original' => null,
            default => "{$dir}/profile.jpg",
        };

        if (is_string($preferred) && $disk->exists($preferred)) {
            return $preferred;
        }

        if ($size !== 'original') {
            $profile = "{$dir}/profile.jpg";
            if ($disk->exists($profile)) {
                return $profile;
            }
        }

        return $this->originalInDirectory($dir);
    }

    /**
     * @return list<array{version: string, is_current: bool, replaced_at: string|null, academic_year: string|null, thumb_url: string|null, profile_url: string|null, original_url: string|null}>
     */
    public function historyFor(PersonPhotoKind $kind, int $id): array
    {
        $items = [];

        if ($this->resolveHistoryPath($kind, $id, 'current', 'profile')
            || $this->resolveHistoryPath($kind, $id, 'current', 'original')) {
            $items[] = $this->historyItem($kind, $id, 'current', $this->lastModifiedAt($kind, $id));
        }

        $stamps = [];
        $versionsDir = $this->versionsDirectory($kind, $id);
        $disk = Storage::disk('private');
        if ($disk->exists($versionsDir)) {
            foreach ($disk->directories($versionsDir) as $directory) {
                $stamp = basename(str_replace('\\', '/', $directory));
                if (preg_match('/^\d{14}$/', $stamp) === 1) {
                    $stamps[] = $stamp;
                }
            }
        }
        rsort($stamps);

        foreach ($stamps as $stamp) {
            $replacedAt = Carbon::createFromFormat('YmdHis', $stamp) ?: null;
            $items[] = $this->historyItem($kind, $id, $stamp, $replacedAt);
        }

        return $items;
    }

    public function signedUrl(PersonPhotoKind $kind, int $id, string $size = 'profile', int $minutes = 60, ?int $versionTs = null): ?string
    {
        if (! $this->fileExists($kind, $id, $size) && ! $this->fileExists($kind, $id, 'original')) {
            return null;
        }

        return URL::temporarySignedRoute(
            'private.person-image',
            now()->addMinutes($minutes),
            [
                'kind' => $kind->value,
                'id' => $id,
                'size' => $this->normalizeSize($size),
                'v' => $versionTs ?? ($this->lastModifiedAt($kind, $id)?->timestamp ?? now()->timestamp),
            ]
        );
    }

    public function signedHistoryUrl(PersonPhotoKind $kind, int $id, string $version, string $size = 'profile', int $minutes = 60): ?string
    {
        if (! $this->isHistoryVersion($version)) {
            return null;
        }

        if (! $this->resolveHistoryPath($kind, $id, $version, $size)
            && ! $this->resolveHistoryPath($kind, $id, $version, 'original')) {
            return null;
        }

        return URL::temporarySignedRoute(
            'private.person-image',
            now()->addMinutes($minutes),
            [
                'kind' => $kind->value,
                'id' => $id,
                'size' => $this->normalizeSize($size),
                'version' => $version,
                'v' => $version === 'current'
                    ? ($this->lastModifiedAt($kind, $id)?->timestamp ?? now()->timestamp)
                    : $version,
            ]
        );
    }

    public function lastModifiedAt(PersonPhotoKind $kind, int $id): ?Carbon
    {
        foreach (['original', 'profile'] as $size) {
            $path = $this->resolvePath($kind, $id, $size);
            if ($path && Storage::disk('private')->exists($path)) {
                return Carbon::createFromTimestamp(Storage::disk('private')->lastModified($path));
            }
        }

        return null;
    }

    /**
     * @return array{thumbnail_url: string|null, profile_url: string|null, original_url: string|null}
     */
    public function currentUrls(PersonPhotoKind $kind, int $id): array
    {
        $hasPhoto = $this->fileExists($kind, $id, 'profile') || $this->fileExists($kind, $id, 'original');
        if (! $hasPhoto) {
            return [
                'thumbnail_url' => null,
                'profile_url' => null,
                'original_url' => null,
            ];
        }

        $version = $this->lastModifiedAt($kind, $id)?->timestamp ?? now()->timestamp;

        return [
            'thumbnail_url' => $this->signedUrl($kind, $id, 'thumb', 60, $version),
            'profile_url' => $this->signedUrl($kind, $id, 'profile', 60, $version),
            'original_url' => $this->signedUrl($kind, $id, 'original', 60, $version),
        ];
    }

    private function historyDirectory(PersonPhotoKind $kind, int $id, string $version): ?string
    {
        if ($version === 'current') {
            return $this->currentDirectory($kind, $id);
        }
        if (preg_match('/^\d{14}$/', $version) !== 1) {
            return null;
        }

        return $this->versionsDirectory($kind, $id).'/'.$version;
    }

    /**
     * @return array{version: string, is_current: bool, replaced_at: string|null, academic_year: string|null, thumb_url: string|null, profile_url: string|null, original_url: string|null}
     */
    private function historyItem(PersonPhotoKind $kind, int $id, string $version, ?Carbon $replacedAt): array
    {
        return [
            'version' => $version,
            'is_current' => $version === 'current',
            'replaced_at' => $replacedAt?->toIso8601String(),
            'academic_year' => null,
            'thumb_url' => $this->signedHistoryUrl($kind, $id, $version, 'thumb'),
            'profile_url' => $this->signedHistoryUrl($kind, $id, $version, 'profile'),
            'original_url' => $this->signedHistoryUrl($kind, $id, $version, 'original'),
        ];
    }

    private function originalInDirectory(string $dir): ?string
    {
        $disk = Storage::disk('private');
        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $candidate = "{$dir}/original.{$ext}";
            if ($disk->exists($candidate)) {
                return $candidate;
            }
        }

        foreach ($disk->files($dir) as $file) {
            if (str_starts_with(basename($file), 'original.')) {
                return $file;
            }
        }

        return null;
    }

    private function normalizeSize(string $size): string
    {
        return in_array($size, ['thumb', 'profile', 'original'], true) ? $size : 'profile';
    }
}
