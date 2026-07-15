<?php

namespace App\Http\Controllers\students;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Services\StudentPhotoPathService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class PrivateImageController extends Controller
{
    public function __construct(
        private readonly StudentPhotoPathService $photoPathService
    ) {}

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
                    'currentEnrollment.classGroup:id,name,grade_level_id',
                ])
                ->findOrFail($id);

            $size = request()->get('size', 'thumb');
            $allowedSizes = ['thumb', 'profile', 'original'];
            if (! in_array($size, $allowedSizes, true)) {
                $size = 'thumb';
            }

            $path = $this->photoPathService->resolveRelativePath($student, $size);

            if (! $path || ! Storage::disk('private')->exists($path)) {
                // Prefer original when optimized size is missing.
                $path = $this->photoPathService->resolveRelativePath($student, 'original');
            }

            if (! $path || ! Storage::disk('private')->exists($path)) {
                // Soft failure: avoids broken <img> noise during attendance tests.
                return response()->noContent();
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
