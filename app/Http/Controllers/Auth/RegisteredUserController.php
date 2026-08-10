<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
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
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', 'unique:'.User::class],
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'exists:roles,name'],
            'permissions' => ['sometimes', 'array'],
            'permissions.*' => ['string', 'exists:permissions,name'],
        ]);

        try {
            $user = DB::transaction(function () use ($validated) {
                $user = User::create([
                    'name' => $validated['name'],
                    'email' => $validated['email'],
                    'password' => Hash::make($validated['password']),
                ]);

                $roles = $validated['roles'] ?? [];
                if (count($roles) > 0) {
                    $user->syncRoles($roles);
                } else {
                    $user->assignRole('user');
                }

                if (! empty($validated['permissions'])) {
                    $user->syncPermissions($validated['permissions']);
                }

                return $user->load('roles', 'permissions');
            });
        } catch (\Throwable $th) {
            Log::error($th);

            return response()->json([
                'message' => __('auth.create-user-error'),
                'error' => $th->getMessage(),
            ], 500);
        }

        event(new Registered($user));

        // Email after commit — failure must not look like a failed create
        $emailSent = true;
        try {
            $user->sendEmailVerificationNotification();
        } catch (\Throwable $th) {
            $emailSent = false;
            Log::warning('User created but verification email failed', [
                'user_id' => $user->id,
                'error' => $th->getMessage(),
            ]);
        }

        return response()->json([
            'message' => $emailSent
                ? __('auth.create-user')
                : __('auth.create-user').' (el correo de verificación no se pudo enviar)',
            'user' => $user,
            'email_sent' => $emailSent,
        ], 201);
    }
}
