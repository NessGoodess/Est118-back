<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

/**
 * Resolve student photo paths and signed URLs.
 *
 * Current layout (legacy):
 *   photos/students/{grade}/{group}/{filename}
 *   photos/students/{grade}/{group}/thumb_{filename}
 *   photos/students/{grade}/{group}/profile_{filename}
 *
 * Preferred future layout (by student id, version-safe):
 *   photos/students/{student_id}/current/original.{ext}
 *   photos/students/{student_id}/current/profile.{ext}
 *   photos/students/{student_id}/current/thumb.{ext}
 *   photos/students/{student_id}/versions/{YmdHis}/...
 */
class StudentPhotoPathService
{
    public function resolveBaseDirectory(Student $student): ?string
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

    public function resolveRelativePath(Student $student, string $size = 'profile'): ?string
    {
        $filename = $student->profile?->profile_picture;
        if (! $filename) {
            return null;
        }

        // New layout (if ever migrated): profile_picture stores "students/{id}/current/profile.jpg"
        if (str_contains($filename, '/')) {
            return str_starts_with($filename, 'photos/') ? $filename : "photos/{$filename}";
        }

        $base = $this->resolveBaseDirectory($student);
        if (! $base) {
            return null;
        }

        return match ($size) {
            'original' => "{$base}/{$filename}",
            'profile' => "{$base}/profile_{$filename}",
            default => "{$base}/thumb_{$filename}",
        };
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

        // Fall back to original when optimized variants are missing.
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
}
