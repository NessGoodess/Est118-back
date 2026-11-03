<?php

namespace App\Services\People;

use App\Enums\PersonPhotoKind;
use App\Models\User;

class UserAvatarService
{
    public function __construct(
        private readonly PersonPhotoPathService $photos,
    ) {}

    /**
     * @return array{
     *   avatar_url: string|null,
     *   profile: array{id: int, first_name: string|null, last_name: string|null, kind: string|null}|null
     * }
     */
    public function sessionPayload(User $user): array
    {
        $user->loadMissing('profile.teacher', 'profile.staff');
        $profile = $user->profile;

        if (! $profile) {
            return [
                'avatar_url' => null,
                'profile' => null,
            ];
        }

        $kind = null;
        $holderId = null;
        if ($profile->teacher) {
            $kind = PersonPhotoKind::Teachers;
            $holderId = (int) $profile->teacher->id;
        } elseif ($profile->staff) {
            $kind = PersonPhotoKind::Staff;
            $holderId = (int) $profile->staff->id;
        }

        $avatarUrl = null;
        if ($kind && $holderId) {
            $avatarUrl = $this->photos->signedUrl($kind, $holderId, 'profile')
                ?? $this->photos->signedUrl($kind, $holderId, 'original');
        }

        return [
            'avatar_url' => $avatarUrl,
            'profile' => [
                'id' => (int) $profile->id,
                'first_name' => $profile->first_name,
                'last_name' => $profile->fullLastName() ?: $profile->last_name,
                'kind' => $kind?->value === 'teachers' ? 'teacher' : ($kind?->value === 'staff' ? 'staff' : null),
            ],
        ];
    }
}
