<?php

namespace App\Http\Controllers\AuthService;

use App\Http\Controllers\Controller;
use App\Enums\ServiceAbility;
use Illuminate\Http\Request;

class AuthServiceController extends Controller
{

    /**
     * Create a new service token
     */
    public function store(Request $request)
    {
        $request->validate([
            
            'name' => 'required|string|max:50',
            'ability' => 'required|string|in:' . implode(',', ServiceAbility::serviceOnly()),
        ]);

        $user = $request->user();

        $token = $user->createToken(
            $request->name,
            [$request->ability]
        );

        return response()->json([
            'token' => $token->plainTextToken,
            'ability' => $request->ability,
        ], 201);
    }

    /**
     * List service tokens
     */
    public function index(Request $request)
    {
        return $request->user()->tokens()->get([
            'id',
            'name',
            'last_used_at',
            'created_at',
        ]);
    }

    /**
     * Revoke service token
     */
    public function destroy(Request $request, string $tokenId)
    {
        $request->user()
            ->tokens()
            ->where('id', $tokenId)
            ->delete();

        return response()->json([
            'message' => 'Token revoked successfully',
        ]);
    }
}
