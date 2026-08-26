<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Resolve student photo paths and signed URLs.
 *
 * Preferred layout (stable by student id):
 *   photos/students/{student_id}/current/original.{ext}
 *   photos/students/{student_id}/current/profile.jpg
 *   photos/students/{student_id}/current/thumb.jpg
 *   photos/students/{student_id}/versions/{YmdHis}/...
 *
 * Legacy layout (still readable until manual cleanup):
 *   photos/students/{grade}/{group}/{filename}
 *   photos/students/{grade}/{group}/thumb_{filename}
 *   photos/students/{grade}/{group}/profile_{filename}
 *
 * profiles.profile_picture for new uploads:
 *   students/{id}/current/profile.jpg
 */
class StudentPhotoPathService
{
    public function stableCurrentDirectory(int $studentId): string
    {
        return "photos/students/{$studentId}/current";
    }

    public function stableVersionsDirectory(int $studentId): string
    {
        return "photos/students/{$studentId}/versions";
    }

    public function stableProfilePictureValue(int $studentId): string
    {
        return "students/{$studentId}/current/profile.jpg";
    }

    public function isStableProfilePicture(?string $value): bool
    {
        if (! $value) {
            return false;
        }

        return (bool) preg_match('#(?:^|/)students/\d+/current/#', $value);
    }

    /**
     * Legacy grade/group directory (null when enrollment/group missing).
     */
    public function resolveLegacyBaseDirectory(Student $student): ?string
    {
        $student->loadMissing([
            'currentEnrollment.classGroup.gradeLevel:id,name',
            'currentEnrollment.classGroup:id,name,grade_level_id',
        ]);

        $grade = $student->currentEnrollment?->classGroup?->gradeLevel?->name;
        $group = $student->currentEnrollment?->classGroup?->name;

        if (! $grade || ! $group) {
            return null;
        }

        return "photos/students/{$grade}/{$group}";
    }

    /** @deprecated Use resolveLegacyBaseDirectory() */
    public function resolveBaseDirectory(Student $student): ?string
    {
        return $this->resolveLegacyBaseDirectory($student);
    }

    public function resolveRelativePath(Student $student, string $size = 'profile'): ?string
    {
        $size = $this->normalizeSize($size);
        $stored = $student->profile?->profile_picture;

        // 1) Stable layout marked in BD, or files already under student_id/current
        $stable = $this->resolveStablePath($student->id, $size, $stored);
        if ($stable) {
            return $stable;
        }

        // 2) Legacy filename — try enrollment grade/group, then basename search (shuffled folders)
        if (! $stored || $this->isStableProfilePicture($stored)) {
            return null;
        }

        $filename = basename($stored);
        $base = $this->resolveLegacyBaseDirectory($student);
        if ($base) {
            $candidate = match ($size) {
                'original' => "{$base}/{$filename}",
                'profile' => "{$base}/profile_{$filename}",
                default => "{$base}/thumb_{$filename}",
            };
            if (Storage::disk('private')->exists($candidate)) {
                return $candidate;
            }
        }

        return $this->findLegacyByBasename($student->id, $filename, $size);
    }

    public function fileExists(Student $student, string $size = 'profile'): bool
    {
        $path = $this->resolveRelativePath($student, $size);
        if (! $path) {
            return false;
        }

        if (Storage::disk('private')->exists($path)) {
            return true;
        }

        if ($size !== 'original') {
            $original = $this->resolveRelativePath($student, 'original');

            return $original ? Storage::disk('private')->exists($original) : false;
        }

        return false;
    }

    public function signedUrl(Student $student, string $size = 'profile', int $minutes = 60): ?string
    {
        if (! $this->fileExists($student, $size) && ! $this->fileExists($student, 'original')) {
            return null;
        }

        $version = $student->profile?->updated_at?->timestamp
            ?? $student->updated_at?->timestamp
            ?? now()->timestamp;

        return URL::temporarySignedRoute(
            'private.image',
            now()->addMinutes($minutes),
            [
                'id' => $student->id,
                'size' => $size,
                'v' => $version,
            ]
        );
    }

    private function resolveStablePath(int $studentId, string $size, ?string $stored): ?string
    {
        $currentDir = $this->stableCurrentDirectory($studentId);
        $disk = Storage::disk('private');

        $preferStable = $this->isStableProfilePicture($stored) || $disk->exists($currentDir);
        if (! $preferStable) {
            return null;
        }

        if ($size === 'profile') {
            $path = "{$currentDir}/profile.jpg";

            return $disk->exists($path) || $this->isStableProfilePicture($stored) ? $path : null;
        }

        if ($size === 'thumb') {
            $path = "{$currentDir}/thumb.jpg";

            return $disk->exists($path) || $this->isStableProfilePicture($stored) ? $path : null;
        }

        foreach (['jpg', 'jpeg', 'png', 'webp'] as $ext) {
            $candidate = "{$currentDir}/original.{$ext}";
            if ($disk->exists($candidate)) {
                return $candidate;
            }
        }

        foreach ($disk->files($currentDir) as $file) {
            if (str_starts_with(basename($file), 'original.')) {
                return $file;
            }
        }

        return $this->isStableProfilePicture($stored) ? "{$currentDir}/original.jpg" : null;
    }

    /**
     * Locate a legacy file by basename anywhere under photos/students.
     * Index is built once per request/process.
     */
    private function findLegacyByBasename(int $studentId, string $filename, string $size): ?string
    {
        $target = match ($size) {
            'original' => $filename,
            'profile' => "profile_{$filename}",
            default => "thumb_{$filename}",
        };

        $matches = $this->legacyBasenameIndex()[strtolower($target)] ?? [];
        if ($matches === []) {
            if ($size !== 'original') {
                return $this->findLegacyByBasename($studentId, $filename, 'original');
            }

            return null;
        }

        if (count($matches) === 1) {
            return $matches[0];
        }

        $needle = "student_{$studentId}_";
        $preferred = array_values(array_filter(
            $matches,
            fn (string $path) => str_contains(basename($path), $needle)
                || str_contains($path, "/{$studentId}/")
        ));

        return $preferred[0] ?? $matches[0];
    }

    /**
     * @return array<string, list<string>>
     */
    private function legacyBasenameIndex(): array
    {
        static $index = null;
        if (is_array($index)) {
            return $index;
        }

        $index = [];
        foreach (Storage::disk('private')->allFiles('photos/students') as $path) {
            if (str_contains($path, '/current/') || str_contains($path, '/versions/')) {
                continue;
            }
            $index[strtolower(basename($path))][] = $path;
        }

        return $index;
    }

    private function normalizeSize(string $size): string
    {
        return in_array($size, ['thumb', 'profile', 'original'], true) ? $size : 'profile';
    }
}
