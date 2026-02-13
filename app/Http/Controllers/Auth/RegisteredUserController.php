<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rules;

class RegisteredUserController extends Controller
{
    /**
     * Handle an incoming registration request.
     * Only accessible by authenticated users with 'create users' permission.
     *
     * @throws \Illuminate\Validation\ValidationException
     */
    public function store(Request $request): JsonResponse
    {
        try {

            //throw $th;
            $request->validate([
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:' . User::class],
                'password' => ['required', 'confirmed', Rules\Password::defaults()],
            ]);

            $user = User::create([
                'name' => $request->name,
                'email' => $request->email,
                'password' => Hash::make($request->input('password')),
            ]);

            event(new Registered($user));

            $user->assignRole('user');
            $user->sendEmailVerificationNotification();

            return response()->json([
                'message' => __('auth.create-user'),
                'user' => $user
            ], 201);
        } catch (\Throwable $th) {
            Log::error($th);
            return response()->json([
                'message' => __('auth.create-user-error'),
                'error' => $th->getMessage()
            ], 500);
        }
    }
}
