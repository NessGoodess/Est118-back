<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

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
        $student->loadMissing([
            'profile:id,profile_picture',
            'currentEnrollment.classGroup.gradeLevel:id,name',
            'currentEnrollment.classGroup:id,name,grade_level_id',
        ]);

        $disk = Storage::disk('private');
        $currentDir = $this->photoPathService->stableCurrentDirectory($student->id);
        $manager = new ImageManager(new Driver());

        $ext = strtolower($photo->getClientOriginalExtension() ?: 'jpg');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }
        if ($ext === 'jpeg') {
            $ext = 'jpg';
        }

        // Archive previous current/ before replacing.
        $this->archiveCurrentIfPresent($student->id);

        // Also clean legacy grade/group files when upgrading from old layout.
        $this->deleteLegacyFilesIfAny($student);

        $disk->makeDirectory($currentDir);

        $originalName = "original.{$ext}";
        $fullOriginalPath = "{$currentDir}/{$originalName}";
        $disk->putFileAs($currentDir, $photo, $originalName);
        $sourcePath = $disk->path($fullOriginalPath);

        $image = $manager->read($sourcePath);
        $thumb = $image->cover(40, 40)->toJpeg(75);
        $disk->put("{$currentDir}/thumb.jpg", (string) $thumb);

        $image = $manager->read($sourcePath);
        $profile = $image->scale(width: 400)->toJpeg(80);
        $disk->put("{$currentDir}/profile.jpg", (string) $profile);

        $dbValue = $this->photoPathService->stableProfilePictureValue($student->id);
        $student->profile?->update(['profile_picture' => $dbValue]);

        return [
            'filename' => $dbValue,
            'path_original' => $fullOriginalPath,
            'path_profile' => "{$currentDir}/profile.jpg",
            'path_thumb' => "{$currentDir}/thumb.jpg",
        ];
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
