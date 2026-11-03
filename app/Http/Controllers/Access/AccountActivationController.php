<?php

namespace App\Http\Controllers\Access;

use App\Http\Controllers\Controller;
use App\Http\Requests\Access\AcceptProfileAccessInviteRequest;
use App\Services\Access\AccountActivationService;
use Illuminate\Http\JsonResponse;

class AccountActivationController extends Controller
{
    public function __construct(
        private readonly AccountActivationService $activations,
    ) {}

    public function show(string $token): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->activations->preview($token),
        ]);
    }

    public function accept(AcceptProfileAccessInviteRequest $request, string $token): JsonResponse
    {
        $user = $this->activations->accept($token, $request->validated('password'));

        return response()->json([
            'success' => true,
            'message' => 'Cuenta activada. Ya puedes iniciar sesión.',
            'data' => [
                'user_id' => $user->id,
                'email' => $user->email,
            ],
        ], 201);
    }
}
