<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Symfony\Component\HttpFoundation\JsonResponse;
use Illuminate\Validation\Rules;

class ChangePasswordController extends Controller
{
    /**
     * Change current user password
     */
    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $request->user()->fill([
            'password' => Hash::make($request->input('password')),
        ])->save();

        return response()->json([
            'message' => __('users.password_updated')
        ], 200);
    }
}