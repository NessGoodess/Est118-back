<?php

namespace App\Http\Controllers\Teachers;

use App\Enums\PersonPhotoKind;
use App\Http\Controllers\Controller;
use App\Services\People\PersonPhotoActionService;
use App\Models\Teacher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TeacherPhotoController extends Controller
{
    public function __construct(
        private readonly PersonPhotoActionService $photos,
    ) {}

    public function history(Teacher $teacher): JsonResponse
    {
        return $this->photos->history(PersonPhotoKind::Teachers, $teacher->id);
    }

    public function upload(Request $request, Teacher $teacher): JsonResponse
    {
        $teacher->loadMissing('profile');
        if ($teacher->profile === null) {
            return response()->json(['success' => false, 'message' => 'El maestro no tiene perfil.'], 422);
        }

        return $this->photos->upload($request, PersonPhotoKind::Teachers, $teacher->id, $teacher->profile);
    }
}
