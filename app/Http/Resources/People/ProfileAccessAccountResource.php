<?php

namespace App\Http\Resources\People;

use App\Models\Profile;
use App\Services\Access\ProfileAccessInviteService;
use App\Services\Access\ProfileAccessLinkService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Profile
 */
class ProfileAccessAccountResource extends JsonResource
{
    /**
     * @return array{
     *   has_user: bool,
     *   user_id: int|null,
     *   pending_invite: array{email: string, expires_at: string|null}|null,
     *   link_candidate: array{id: int, name: string, email: string}|null
     * }
     */
    public function toArray(Request $request): array
    {
        /** @var Profile $profile */
        $profile = $this->resource;
        $linked = $profile->linkedUser();
        $pending = app(ProfileAccessInviteService::class)->pendingForProfile($profile);
        $candidate = $linked
            ? null
            : app(ProfileAccessLinkService::class)->matchPayload($profile);

        return [
            'has_user' => $linked !== null,
            'user_id' => $linked?->id ? (int) $linked->id : null,
            'pending_invite' => $pending ? [
                'email' => $pending->email,
                'expires_at' => $pending->expires_at?->toIso8601String(),
            ] : null,
            'link_candidate' => $candidate,
        ];
    }
}
