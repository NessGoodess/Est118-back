<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserDetailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'email_verified_at' => $this->email_verified_at,
            'deleted_at' => $this->deleted_at,

            'roles' => $this->roles->map(fn($r) => [
                'id' => $r->id,
                'name' => $r->name,
            ]),

            'permissions' => $this->permissions->map(fn($p) => [
                'id' => $p->id,
                'name' => $p->name,
            ]),
        ];
    }
}
