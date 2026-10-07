<?php

namespace App\Http\Controllers\Staff;

use App\Enums\PersonPhotoKind;
use App\Http\Controllers\Controller;
use App\Services\People\PersonPhotoActionService;
use App\Models\Staff;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffPhotoController extends Controller
{
    public function __construct(
        private readonly PersonPhotoActionService $photos,
    ) {}

    public function history(Staff $staff): JsonResponse
    {
        return $this->photos->history(PersonPhotoKind::Staff, $staff->id);
    }

    public function upload(Request $request, Staff $staff): JsonResponse
    {
        $staff->loadMissing('profile');
        if ($staff->profile === null) {
            return response()->json(['success' => false, 'message' => 'El personal no tiene perfil.'], 422);
        }

        return $this->photos->upload($request, PersonPhotoKind::Staff, $staff->id, $staff->profile);
    }
}
