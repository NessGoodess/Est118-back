<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;
use Throwable;

/**
 * Persist student photos under a stable student_id layout.
 *
 * photos/students/{id}/current/{original,profile,thumb}
 * photos/students/{id}/versions/{YmdHis}/...  (previous current on renew)
 */
class StudentPhotoService
{
    public function __construct(
        private readonly StudentPhotoPathService $photoPathService
    ) {}

    /**
     * Store original + optimized profile/thumb images for a student.
     *
     * @return array{filename:string, path_original:string, path_profile:string, path_thumb:string}
     */
    public function storeStudentPhoto(Student $student, UploadedFile $photo): array
    {
        $fullOriginalPath = null;

        try {
            $student->loadMissing([
                'profile:id,profile_picture',
                'currentEnrollment.classGroup.gradeLevel:id,name',
                'currentEnrollment.classGroup:id,name,grade_level_id',
            ]);

            $disk = Storage::disk('private');
            $currentDir = $this->photoPathService->stableCurrentDirectory($student->id);

            $ext = strtolower($photo->getClientOriginalExtension() ?: 'jpg');
            if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
                $ext = 'jpg';
            }
            if ($ext === 'jpeg') {
                $ext = 'jpg';
            }

            $this->archiveCurrentIfPresent($student->id);
            $this->deleteLegacyFilesIfAny($student);

            $disk->makeDirectory($currentDir);

            $originalName = "original.{$ext}";
            $fullOriginalPath = "{$currentDir}/{$originalName}";
            $disk->putFileAs($currentDir, $photo, $originalName);

            $this->normalizeOriginalIfNeeded($student->id, $fullOriginalPath, $ext);
            $this->writeVariantsForOriginal($fullOriginalPath);

            $dbValue = $this->photoPathService->stableProfilePictureValue($student->id);
            $student->profile?->update(['profile_picture' => $dbValue]);

            return [
                'filename' => $dbValue,
                'path_original' => $fullOriginalPath,
                'path_profile' => "{$currentDir}/profile.jpg",
                'path_thumb' => "{$currentDir}/thumb.jpg",
            ];
        } catch (Throwable $e) {
            Log::error('[toma-foto] No se pudo guardar o convertir la foto', [
                'student_id' => $student->id,
                'path' => $fullOriginalPath,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Rewrite original pixels upright when EXIF orientation is not 1.
     */
    public function normalizeOriginalIfNeeded(int $studentId, string $relativePath, ?string $ext = null): bool
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
        $base = basename($originalRelative);
        $stable = (bool) preg_match('#/current$#', $dir);
        $thumbRel = $stable ? "{$dir}/thumb.jpg" : "{$dir}/thumb_{$base}";
        $profileRel = $stable ? "{$dir}/profile.jpg" : "{$dir}/profile_{$base}";

        $manager = new ImageManager(new Driver());
        $thumb = $manager->read($absolute)->cover(40, 40)->toJpeg(75);
        $disk->put($thumbRel, (string) $thumb);

        $profile = $manager->read($absolute)->scale(width: 400)->toJpeg(80);
        $disk->put($profileRel, (string) $profile);
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

    private function archiveCurrentIfPresent(int $studentId): void
    {
        $disk = Storage::disk('private');
        $currentDir = $this->photoPathService->stableCurrentDirectory($studentId);

        if (! $disk->exists($currentDir)) {
            return;
        }

        $files = $disk->files($currentDir);
        if ($files === []) {
            return;
        }

        $versionDir = $this->photoPathService->stableVersionsDirectory($studentId).'/'.now()->format('YmdHis');
        $disk->makeDirectory($versionDir);

        foreach ($files as $file) {
            $disk->move($file, $versionDir.'/'.basename($file));
        }
    }

    private function deleteLegacyFilesIfAny(Student $student): void
    {
        $previous = $student->profile?->profile_picture;
        if (! $previous || str_contains($previous, '/')) {
            return;
        }

        $base = $this->photoPathService->resolveLegacyBaseDirectory($student);
        if (! $base) {
            return;
        }

        $disk = Storage::disk('private');
        foreach ([$previous, "thumb_{$previous}", "profile_{$previous}"] as $old) {
            $oldPath = "{$base}/{$old}";
            if ($disk->exists($oldPath)) {
                $disk->delete($oldPath);
            }
        }
    }
}
