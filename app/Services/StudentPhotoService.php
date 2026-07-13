<?php

namespace App\Services;

use App\Models\Student;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Intervention\Image\Drivers\Gd\Driver;
use Intervention\Image\ImageManager;

class StudentPhotoService
{
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

        $grade = $student->currentEnrollment?->classGroup?->gradeLevel?->name;
        $group = $student->currentEnrollment?->classGroup?->name;
        if (! $grade || ! $group) {
            throw new \RuntimeException('El alumno no tiene grupo/grado activo para guardar la foto.');
        }

        $directory = "photos/students/{$grade}/{$group}";
        $manager = new ImageManager(new Driver());

        $ext = strtolower($photo->getClientOriginalExtension() ?: 'jpg');
        if (! in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true)) {
            $ext = 'jpg';
        }

        $filename = sprintf('student_%d_%s.%s', $student->id, now()->format('YmdHis'), $ext);
        $fullOriginalPath = "{$directory}/{$filename}";

        // Keep original uploaded file
        Storage::disk('private')->putFileAs($directory, $photo, $filename);
        $sourcePath = Storage::disk('private')->path($fullOriginalPath);

        // Generate optimized variants (same behavior as command).
        $image = $manager->read($sourcePath);
        $thumb = $image->cover(40, 40)->toJpeg(75);
        Storage::disk('private')->put("{$directory}/thumb_{$filename}", (string) $thumb);

        $image = $manager->read($sourcePath);
        $profile = $image->scale(width: 400)->toJpeg(80);
        Storage::disk('private')->put("{$directory}/profile_{$filename}", (string) $profile);

        // Optional cleanup of previous files if they existed in same directory.
        $previous = $student->profile?->profile_picture;
        if ($previous && $previous !== $filename) {
            foreach ([$previous, "thumb_{$previous}", "profile_{$previous}"] as $old) {
                $oldPath = "{$directory}/{$old}";
                if (Storage::disk('private')->exists($oldPath)) {
                    Storage::disk('private')->delete($oldPath);
                }
            }
        }

        $student->profile?->update(['profile_picture' => $filename]);

        return [
            'filename' => $filename,
            'path_original' => $fullOriginalPath,
            'path_profile' => "{$directory}/profile_{$filename}",
            'path_thumb' => "{$directory}/thumb_{$filename}",
        ];
    }
}

