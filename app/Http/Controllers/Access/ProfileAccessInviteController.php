<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\AcceptProfileAccessInviteRequest;
use App\Http\Requests\Access\StoreProfileAccessInviteRequest;
use App\Models\Staff;
use App\Models\Teacher;
use App\Models\ProfileAccessInvite;
use App\Services\Access\ProfileAccessInviteService;
use Illuminate\Http\JsonResponse;

class ProfileAccessInviteController extends Controller
{
    public function __construct(
        private readonly ProfileAccessInviteService $invites,
    ) {}

    public function storeTeacher(StoreProfileAccessInviteRequest $request, Teacher $teacher): JsonResponse
    {
        $teacher->loadMissing('profile');

        $result = $this->invites->createForProfile(
            profile: $teacher->profile,
            invitedBy: $request->user(),
            email: $request->validated('email'),
            roles: $request->validated('roles'),
            permissions: $request->validated('permissions'),
            defaultRole: 'teacher',
        );

        return $this->inviteCreatedResponse($result['invite']);
    }

    public function storeStaff(StoreProfileAccessInviteRequest $request, Staff $staff): JsonResponse
    {
        $staff->loadMissing('profile');

        $result = $this->invites->createForProfile(
            profile: $staff->profile,
            invitedBy: $request->user(),
            email: $request->validated('email'),
            roles: $request->validated('roles'),
            permissions: $request->validated('permissions'),
            defaultRole: 'staff',
        );

        return $this->inviteCreatedResponse($result['invite']);
    }

    public function destroyTeacher(Teacher $teacher): JsonResponse
    {
        $teacher->loadMissing('profile');
        $this->invites->revokePending($teacher->profile);

        return response()->json([
            'success' => true,
            'message' => 'Invitación revocada.',
        ]);
    }

    public function destroyStaff(Staff $staff): JsonResponse
    {
        $staff->loadMissing('profile');
        $this->invites->revokePending($staff->profile);

        return response()->json([
            'success' => true,
            'message' => 'Invitación revocada.',
        ]);
    }

    public function show(string $token): JsonResponse
    {
        $preview = $this->invites->preview($token);

        return response()->json([
            'success' => true,
            'data' => $preview,
        ]);
    }

    public function accept(AcceptProfileAccessInviteRequest $request, string $token): JsonResponse
    {
        $user = $this->invites->accept($token, $request->validated('password'));

        return response()->json([
            'success' => true,
            'message' => 'Cuenta creada. Ya puedes iniciar sesión.',
            'data' => [
                'user_id' => $user->id,
                'email' => $user->email,
            ],
        ], 201);
    }

    private function inviteCreatedResponse(ProfileAccessInvite $invite): JsonResponse
    {
        return response()->json([
            'success' => true,
            'message' => 'Invitación enviada.',
            'data' => [
                'email' => $invite->email,
                'expires_at' => $invite->expires_at?->toIso8601String(),
                'roles' => $invite->roles,
            ],
        ], 201);
    }
}
