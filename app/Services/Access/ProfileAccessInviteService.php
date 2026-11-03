<?php

namespace App\Services\Access;

use App\Models\Profile;
use App\Models\ProfileAccessInvite;
use App\Models\ProfileUserLinkAudit;
use App\Models\User;
use App\Models\UserPreference;
use App\Notifications\ProfileAccessInviteNotification;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

class ProfileAccessInviteService
{
    public const EXPIRES_DAYS = 7;

    /** @var list<string> */
    private const FORBIDDEN_ROLES = ['admin'];

    /**
     * @param  list<string>|null  $roles
     * @param  list<string>|null  $permissions
     * @return array{invite: ProfileAccessInvite, plain_token: string}
     */
    public function createForProfile(
        Profile $profile,
        User $invitedBy,
        ?string $email = null,
        ?array $roles = null,
        ?array $permissions = null,
        string $defaultRole = 'user',
    ): array {
        if ($profile->hasLinkedUser()) {
            throw ValidationException::withMessages([
                'profile' => ['Este perfil ya tiene una cuenta vinculada.'],
            ]);
        }

        $email = strtolower(trim($email ?: (string) $profile->email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw ValidationException::withMessages([
                'email' => ['Indica un correo válido para la invitación.'],
            ]);
        }

        $existing = User::withTrashed()->where('email', $email)->first();
        if ($existing && $existing->deleted_at === null) {
            throw ValidationException::withMessages([
                'email' => ['Ya existe una cuenta activa con este correo. Usa “Vincular cuenta”.'],
            ]);
        }

        $roles = $this->normalizeRoles($roles ?? [$defaultRole]);
        $permissions = $this->normalizePermissions($permissions);

        $plainToken = Str::random(64);
        $tokenHash = hash('sha256', $plainToken);

        $invite = DB::transaction(function () use ($profile, $email, $tokenHash, $roles, $permissions, $invitedBy) {
            ProfileAccessInvite::query()
                ->where('profile_id', $profile->id)
                ->whereNull('accepted_at')
                ->delete();

            return ProfileAccessInvite::query()->create([
                'profile_id' => $profile->id,
                'email' => $email,
                'token_hash' => $tokenHash,
                'roles' => $roles,
                'permissions' => $permissions,
                'invited_by' => $invitedBy->id,
                'expires_at' => now()->addDays(self::EXPIRES_DAYS),
            ]);
        });

        $invite->load('profile');
        Notification::route('mail', $invite->email)
            ->notify(new ProfileAccessInviteNotification($plainToken, $invite));

        return ['invite' => $invite, 'plain_token' => $plainToken];
    }

    public function findPendingByToken(string $token): ProfileAccessInvite
    {
        $invite = ProfileAccessInvite::query()
            ->with(['profile.teacher', 'profile.staff'])
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $invite) {
            throw ValidationException::withMessages([
                'token' => ['La invitación no es válida.'],
            ]);
        }

        if ($invite->accepted_at !== null) {
            throw ValidationException::withMessages([
                'token' => ['Esta invitación ya fue aceptada.'],
            ]);
        }

        if ($invite->isExpired()) {
            throw ValidationException::withMessages([
                'token' => ['Esta invitación ha expirado.'],
            ]);
        }

        return $invite;
    }

    /**
     * @return array{name: string, email: string, roles: list<string>, expires_at: string}
     */
    public function preview(string $token): array
    {
        $invite = $this->findPendingByToken($token);
        $profile = $invite->profile;

        return [
            'name' => $profile?->fullName() ?: 'Usuario',
            'email' => $invite->email,
            'roles' => array_values($invite->roles ?? []),
            'expires_at' => $invite->expires_at?->toIso8601String() ?? '',
        ];
    }

    public function accept(string $token, string $password): User
    {
        $invite = $this->findPendingByToken($token);
        $profile = $invite->profile;

        if (! $profile) {
            throw ValidationException::withMessages([
                'token' => ['El perfil de la invitación ya no existe.'],
            ]);
        }

        if ($profile->hasLinkedUser()) {
            throw ValidationException::withMessages([
                'profile' => ['Este perfil ya tiene una cuenta vinculada.'],
            ]);
        }

        $existing = User::withTrashed()->where('email', $invite->email)->first();
        if ($existing && $existing->deleted_at === null) {
            throw ValidationException::withMessages([
                'email' => ['Ya existe una cuenta activa con este correo. Pide a un administrador que vincule la cuenta.'],
            ]);
        }

        try {
            return DB::transaction(function () use ($invite, $profile, $password, $existing) {
                $action = ProfileUserLinkAudit::ACTION_INVITE_ACCEPT;

                if ($existing && $existing->trashed()) {
                    $existing->restore();
                    $existing->forceFill([
                        'name' => $profile->fullName() ?: $invite->email,
                        'password' => Hash::make($password),
                        'email_verified_at' => now(),
                    ])->save();
                    $user = $existing->fresh();
                    $action = ProfileUserLinkAudit::ACTION_INVITE_RESTORE;
                } else {
                    $user = User::query()->create([
                        'name' => $profile->fullName() ?: $invite->email,
                        'email' => $invite->email,
                        'password' => Hash::make($password),
                    ]);
                    $user->forceFill(['email_verified_at' => now()])->save();
                }

                $roles = $this->normalizeRoles($invite->roles ?? []);
                if ($roles === []) {
                    $user->assignRole('user');
                } else {
                    $user->syncRoles($roles);
                }

                $permissions = $this->normalizePermissions($invite->permissions);
                if ($permissions !== []) {
                    $user->syncPermissions($permissions);
                }

                $profile->user_id = $user->id;
                $profile->save();

                UserPreference::query()->firstOrCreate(
                    ['user_id' => $user->id],
                    UserPreference::defaults(),
                );

                $invite->accepted_at = now();
                $invite->accepted_user_id = $user->id;
                $invite->save();

                ProfileAccessInvite::query()
                    ->where('profile_id', $profile->id)
                    ->whereNull('accepted_at')
                    ->whereKeyNot($invite->id)
                    ->delete();

                ProfileUserLinkAudit::query()->create([
                    'profile_id' => $profile->id,
                    'user_id' => $user->id,
                    'linked_by' => $invite->invited_by,
                    'action' => $action,
                    'email_matched' => true,
                    'meta' => ['invite_id' => $invite->id],
                ]);

                return $user->load('roles', 'permissions');
            });
        } catch (UniqueConstraintViolationException $exception) {
            throw ValidationException::withMessages([
                'email' => ['Ya existe una cuenta con este correo.'],
            ]);
        }
    }

    public function revokePending(Profile $profile): void
    {
        ProfileAccessInvite::query()
            ->where('profile_id', $profile->id)
            ->whereNull('accepted_at')
            ->delete();
    }

    public function pendingForProfile(Profile $profile): ?ProfileAccessInvite
    {
        return ProfileAccessInvite::query()
            ->where('profile_id', $profile->id)
            ->pending()
            ->latest('id')
            ->first();
    }

    /**
     * @param  list<string>  $roles
     * @return list<string>
     */
    private function normalizeRoles(array $roles): array
    {
        $roles = array_values(array_unique(array_filter(array_map(
            static fn ($role) => is_string($role) ? trim($role) : '',
            $roles
        ))));

        foreach ($roles as $role) {
            if (in_array($role, self::FORBIDDEN_ROLES, true)) {
                throw ValidationException::withMessages([
                    'roles' => ['No se puede asignar el rol admin por invitación.'],
                ]);
            }
            if (! Role::query()->where('name', $role)->where('guard_name', 'web')->exists()) {
                throw ValidationException::withMessages([
                    'roles' => ["El rol \"{$role}\" no existe."],
                ]);
            }
        }

        return $roles;
    }

    /**
     * @param  list<string>|null  $permissions
     * @return list<string>
     */
    private function normalizePermissions(?array $permissions): array
    {
        if ($permissions === null || $permissions === []) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn ($permission) => is_string($permission) ? trim($permission) : '',
            $permissions
        ))));
    }
}
