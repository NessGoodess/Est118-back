<?php

namespace App\Http\Controllers\Guardians;

use App\Enums\PersonPhotoKind;
use App\Http\Controllers\Controller;
use App\Models\Guardian;
use App\Services\People\PersonPhotoActionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GuardianPhotoController extends Controller
{
    public function __construct(
        private readonly PersonPhotoActionService $photos,
    ) {}

    public function history(Guardian $guardian): JsonResponse
    {
        return $this->photos->history(PersonPhotoKind::Guardians, $guardian->id);
    }

    public function upload(Request $request, Guardian $guardian): JsonResponse
    {
        $guardian->loadMissing('profile');
        if ($guardian->profile === null) {
            return response()->json(['success' => false, 'message' => 'El tutor no tiene perfil.'], 422);
        }

        return $this->photos->upload($request, PersonPhotoKind::Guardians, $guardian->id, $guardian->profile);
    }
}
