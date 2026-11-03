<?php

namespace App\Services\Access;

use App\Models\AccountActivation;
use App\Models\User;
use App\Models\UserPreference;
use App\Notifications\AccountActivationNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AccountActivationService
{
    public const EXPIRES_DAYS = 7;

    /**
     * @return array{activation: AccountActivation, plain_token: string}
     */
    public function issueForUser(User $user, ?User $createdBy = null): array
    {
        $plainToken = Str::random(64);
        $tokenHash = hash('sha256', $plainToken);

        $activation = DB::transaction(function () use ($user, $tokenHash, $createdBy) {
            AccountActivation::query()
                ->where('user_id', $user->id)
                ->whereNull('accepted_at')
                ->delete();

            return AccountActivation::query()->create([
                'user_id' => $user->id,
                'token_hash' => $tokenHash,
                'expires_at' => now()->addDays(self::EXPIRES_DAYS),
                'created_by' => $createdBy?->id,
            ]);
        });

        Notification::route('mail', $user->email)
            ->notify(new AccountActivationNotification($plainToken, $user));

        return ['activation' => $activation, 'plain_token' => $plainToken];
    }

    public function findPendingByToken(string $token): AccountActivation
    {
        $activation = AccountActivation::query()
            ->with('user')
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $activation) {
            throw ValidationException::withMessages([
                'token' => ['La activación no es válida.'],
            ]);
        }

        if ($activation->accepted_at !== null) {
            throw ValidationException::withMessages([
                'token' => ['Esta activación ya fue usada.'],
            ]);
        }

        if ($activation->isExpired()) {
            throw ValidationException::withMessages([
                'token' => ['Esta activación ha expirado.'],
            ]);
        }

        if (! $activation->user) {
            throw ValidationException::withMessages([
                'token' => ['La cuenta de activación ya no existe.'],
            ]);
        }

        return $activation;
    }

    /**
     * @return array{name: string, email: string, expires_at: string}
     */
    public function preview(string $token): array
    {
        $activation = $this->findPendingByToken($token);
        $user = $activation->user;

        return [
            'name' => (string) ($user?->name ?: 'Usuario'),
            'email' => (string) ($user?->email ?: ''),
            'expires_at' => $activation->expires_at?->toIso8601String() ?? '',
        ];
    }

    public function accept(string $token, string $password): User
    {
        $activation = $this->findPendingByToken($token);
        $user = $activation->user;

        return DB::transaction(function () use ($activation, $user, $password) {
            $user->forceFill([
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ])->save();

            UserPreference::query()->firstOrCreate(
                ['user_id' => $user->id],
                UserPreference::defaults(),
            );

            $activation->accepted_at = now();
            $activation->save();

            AccountActivation::query()
                ->where('user_id', $user->id)
                ->whereNull('accepted_at')
                ->whereKeyNot($activation->id)
                ->delete();

            return $user->fresh()->load('roles', 'permissions');
        });
    }
}
