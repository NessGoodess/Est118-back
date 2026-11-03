<?php

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;

class StoreProfileAccessInviteRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'max:64'],
            'permissions' => ['sometimes', 'nullable', 'array'],
            'permissions.*' => ['string', 'max:128'],
        ];
    }
}
