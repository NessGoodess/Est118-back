<?php

namespace App\Http\Requests\Access;

use Illuminate\Foundation\Http\FormRequest;

class LinkProfileAccessRequest extends FormRequest
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
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'confirm' => ['sometimes', 'boolean'],
        ];
    }
}
