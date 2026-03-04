<?php

namespace App\Http\Controllers\students;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class PrivateImageController extends Controller
{
    /**
     * Display the specified resource.
     */
    public function showById($id)
    {
        try {
            $student = \App\Models\Student::with(['profile', 'currentGroup.gradeLevel'])->findOrFail($id);

            $grade = $student->currentGroup?->gradeLevel?->name;
            $group = $student->currentGroup?->name;
            $photo = $student->profile?->profile_picture;

            if ($grade && $group && $photo) {
                $path = 'photos/students/' . $grade . '/' . $group . '/' . $photo;
            } else {
                $path = 'photos/students/default.png';
            }

            if (!Storage::disk('private')->exists($path)) {
                Log::error('Image not found for student ' . $id . ': ' . $path);

                return response()->json([
                    'success' => false,
                    'message' => 'not found',
                ], 404);
            }

            return Storage::disk('private')->response($path, null, [
                'Cache-Control' => 'private, max-age=86400',
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'student not found',
            ], 404);
        } catch (\Throwable $e) {
            Log::error('Error al obtener la imagen: ', [
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
