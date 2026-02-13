<?php

namespace App\Http\Controllers;

use App\Http\Resources\UserDetailResource;
use App\Http\Resources\UserListResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    /**
     * Display a listing of users with filters
     */
    public function index(Request $request)
    {
        $query = User::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->has('role')) {
            $query->role($request->input('role'));
        }

        if ($request->has('verified')) {
            if ($request->input('verified') === 'true') {
                $query->whereNotNull('email_verified_at');
            } else {
                $query->whereNull('email_verified_at');
            }
        }

        $users = $query->orderByDesc('created_at')->paginate(20);

        return UserListResource::collection($users);
    }

    /**
     * Display the specified user
     */
    public function show(User $user): UserDetailResource
    {
        $user->load('roles.permissions', 'permissions');

        return new UserDetailResource($user);
    }

    /**
     * Update the specified user
     */
   public function update(Request $request, User $user): JsonResponse
{
    $request->validate([
        'name' => ['sometimes', 'string', 'max:255'],
        'email' => ['sometimes', 'string', 'email', 'max:255', 'unique:users,email,' . $user->id],
        'roles' => ['sometimes', 'array'],
        'roles.*' => ['exists:roles,name'],
        'permissions' => ['sometimes', 'array'],
        'permissions.*' => ['exists:permissions,name'],
    ]);

    if ($request->has('name')) {
        $user->name = $request->input('name');
    }

    if ($request->has('email') && $request->email !== $user->email) {
        $user->email = $request->input('email');
        $user->email_verified_at = null;
    }

    $user->save();

    if ($request->has('roles')) {
        $user->syncRoles($request->input('roles'));
    }

    if ($request->has('permissions')) {
        $user->syncPermissions($request->input('permissions'));
    }

    $user->load('roles', 'permissions');

    return response()->json([
        'message' => __('users.updated'),
        'user' => [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'roles' => $user->roles->pluck('name'),
            'permissions' => $user->getAllPermissions()->pluck('name'),
        ]
    ]);
}


    /**
     * Soft delete the specified user
     */
    public function destroy(User $user): JsonResponse
    {
        // Prevent self-deletion
        if ($user->id === Auth::user()->id) {
            return response()->json([
                'message' => __('users.cannot_delete_self')
            ], 422);
        }

        $user->delete();

        return response()->json([
            'message' => __('users.deleted')
        ]);
    }

    /**
     * Change user password (admin only)
     */
    public function changePassword(Request $request, User $user): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'confirmed', Rules\Password::defaults()],
        ]);

        $user->password = Hash::make($request->input('password'));
        $user->save();

        return response()->json([
            'message' => __('users.password_updated')
        ]);
    }

    /**
     * Resend email verification notification
     */
    public function resendVerification(User $user): JsonResponse
    {
        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'message' => __('users.already_verified')
            ], 422);
        }

        $user->sendEmailVerificationNotification();

        return response()->json([
            'message' => __('users.verification_sent')
        ]);
    }

    /**
     * Get all roles with their permissions
     */
    public function roles(): JsonResponse
    {
        return response()->json(
            Role::with('permissions')->get()
        );
    }

    /**
     * Get all permissions grouped by category
     */
    public function permissions(): JsonResponse
    {
        $permissions = Permission::all()->groupBy(function ($permission) {
            // Group by the second word (e.g., "create users" -> "users")
            $parts = explode(' ', $permission->name);
            return $parts[1] ?? 'general';
        });

        return response()->json($permissions);
    }
}
