<?php

namespace App\Services\Access;

use App\Models\Profile;
use App\Models\ProfileUserLinkAudit;
use App\Models\User;
use App\Models\UserPreference;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProfileAccessLinkService
{
    public function matchByEmail(Profile $profile): ?User
    {
        if ($profile->hasLinkedUser()) {
            return null;
        }

        $email = strtolower(trim((string) $profile->email));
        if ($email === '' || ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return null;
        }

        $user = User::query()->where('email', $email)->first();
        if (! $user) {
            return null;
        }

        if (Profile::query()->where('user_id', $user->id)->exists()) {
            return null;
        }

        return $user;
    }

    /**
     * @return array{id: int, name: string, email: string}|null
     */
    public function matchPayload(Profile $profile): ?array
    {
        $user = $this->matchByEmail($profile);
        if (! $user) {
            return null;
        }

        return [
            'id' => (int) $user->id,
            'name' => (string) $user->name,
            'email' => (string) $user->email,
        ];
    }

    public function link(Profile $profile, User $user, User $actor, bool $confirm = false): void
    {
        if ($profile->hasLinkedUser()) {
            throw ValidationException::withMessages([
                'profile' => ['Este perfil ya tiene una cuenta vinculada.'],
            ]);
        }

        if (Profile::query()->where('user_id', $user->id)->exists()) {
            throw ValidationException::withMessages([
                'user_id' => ['Esa cuenta ya está vinculada a otro perfil.'],
            ]);
        }

        $profileEmail = strtolower(trim((string) $profile->email));
        $userEmail = strtolower(trim((string) $user->email));
        $emailMatched = $profileEmail !== '' && $profileEmail === $userEmail;

        if (! $emailMatched && ! $confirm) {
            throw ValidationException::withMessages([
                'confirm' => ['El correo del perfil no coincide con la cuenta. Confirma explícitamente para vincular.'],
            ]);
        }

        DB::transaction(function () use ($profile, $user, $actor, $emailMatched) {
            $profile->user_id = $user->id;
            $profile->save();

            UserPreference::query()->firstOrCreate(
                ['user_id' => $user->id],
                UserPreference::defaults(),
            );

            ProfileUserLinkAudit::query()->create([
                'profile_id' => $profile->id,
                'user_id' => $user->id,
                'linked_by' => $actor->id,
                'action' => ProfileUserLinkAudit::ACTION_LINK,
                'email_matched' => $emailMatched,
                'meta' => [
                    'profile_email' => $profile->email,
                    'user_email' => $user->email,
                ],
            ]);
        });
    }
}
