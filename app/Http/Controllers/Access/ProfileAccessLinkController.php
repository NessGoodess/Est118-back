<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\LinkProfileAccessRequest;
use App\Models\Staff;
use App\Models\Teacher;
use App\Models\User;
use App\Services\Access\ProfileAccessLinkService;
use Illuminate\Http\JsonResponse;

class ProfileAccessLinkController extends Controller
{
    public function __construct(
        private readonly ProfileAccessLinkService $links,
    ) {}

    public function matchTeacher(Teacher $teacher): JsonResponse
    {
        $teacher->loadMissing('profile');

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $this->links->matchPayload($teacher->profile),
            ],
        ]);
    }

    public function matchStaff(Staff $staff): JsonResponse
    {
        $staff->loadMissing('profile');

        return response()->json([
            'success' => true,
            'data' => [
                'user' => $this->links->matchPayload($staff->profile),
            ],
        ]);
    }

    public function linkTeacher(LinkProfileAccessRequest $request, Teacher $teacher): JsonResponse
    {
        $teacher->loadMissing('profile');
        $user = User::query()->findOrFail($request->integer('user_id'));

        $this->links->link(
            profile: $teacher->profile,
            user: $user,
            actor: $request->user(),
            confirm: (bool) $request->boolean('confirm'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Cuenta vinculada al perfil.',
        ]);
    }

    public function linkStaff(LinkProfileAccessRequest $request, Staff $staff): JsonResponse
    {
        $staff->loadMissing('profile');
        $user = User::query()->findOrFail($request->integer('user_id'));

        $this->links->link(
            profile: $staff->profile,
            user: $user,
            actor: $request->user(),
            confirm: (bool) $request->boolean('confirm'),
        );

        return response()->json([
            'success' => true,
            'message' => 'Cuenta vinculada al perfil.',
        ]);
    }
}
