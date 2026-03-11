<?php

namespace App\Http\Controllers\students;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use App\Models\Student;
use Illuminate\Database\Eloquent\ModelNotFoundException;

class PrivateImageController extends Controller
{
    /**
     * Display the specified resource.
     */
    public function showById($id)
    {
        try {
            $student = Student::select('id', 'profile_id')
                ->with([
                    'profile:id,profile_picture',
                    'currentEnrollment.classGroup.gradeLevel:id,name',
                    'currentEnrollment.classGroup:id,name,grade_level_id'
                ])
                ->findOrFail($id);

            $grade = $student->currentEnrollment?->classGroup?->gradeLevel?->name;
            $group = $student->currentEnrollment?->classGroup?->name;
            $filename = $student->profile?->profile_picture;

            if (!$filename || !$grade || !$group) {
                return response()->noContent();
            }

            $size = request()->get('size', 'thumb');
            $allowedSizes = ['thumb', 'profile', 'original'];

            if (!in_array($size, $allowedSizes)) {
                $size = 'thumb';
            }

            $basePath = "photos/students/{$grade}/{$group}";

            $path = match ($size) {
                'original' => "{$basePath}/{$filename}",
                'profile' => "{$basePath}/profile_{$filename}",
                default => "{$basePath}/thumb_{$filename}",
            };

            if (!Storage::disk('private')->exists($path)) {
                Log::error('Image not found for student ' . $id . ': ' . $path);

                return response()->json([
                    'success' => false,
                    'message' => 'not found',
                ], 404);
            }

            return Storage::disk('private')->response($path, null, [
                'Cache-Control' => 'private, max-age=86400, immutable',
            ]);

        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'student not found',
            ], 404);

        } catch (\Throwable $e) {
            Log::error('Error getting image', [
                'error' => $e->getMessage(),
                'student_id' => $id,
                'user_id' => request()->user()->id ?? 'guest',
                'ip' => request()->ip(),
                'method' => request()->method(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'internal server error',
            ], 500);
        }
    }
}
