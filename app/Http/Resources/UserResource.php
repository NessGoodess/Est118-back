<?php

namespace App\Http\Resources;

use App\Models\User;
use App\Models\UserPreference;
use App\Services\People\UserAvatarService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var User $user */
        $user = $this->resource;
        $avatar = app(UserAvatarService::class)->sessionPayload($user);
        $preferences = $user->preference
            ? $user->preference->toSessionArray()
            : UserPreference::defaults();

        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'email_verified_at' => $user->email_verified_at,
            'created_at' => $user->created_at,
            'updated_at' => $user->updated_at,
            'deleted_at' => $user->deleted_at,
            'roles' => $user->roles->pluck('name'),
            'permissions' => $user->getAllPermissions()->pluck('name'),
            'teacher_id' => $user->linkedTeacherId(),
            'schedule_scope' => $user->scheduleScope(),
            'avatar_url' => $avatar['avatar_url'],
            'profile' => $avatar['profile'],
            'preferences' => $preferences,
        ];
    }
}
