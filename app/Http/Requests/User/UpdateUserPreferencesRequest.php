<?php

namespace App\Http\Requests\User;

use App\Models\UserPreference;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateUserPreferencesRequest extends FormRequest
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
            'theme' => ['sometimes', Rule::in(UserPreference::THEMES)],
            'sidebar_mode' => ['sometimes', Rule::in(UserPreference::SIDEBAR_MODES)],
            'idle_logout_minutes' => [
                'sometimes',
                'nullable',
                'integer',
                'min:'.UserPreference::IDLE_MIN,
                'max:'.UserPreference::IDLE_MAX,
            ],
            'shell_colors' => ['sometimes', 'array'],
            'shell_colors.sidebar' => [
                'sometimes',
                'string',
                Rule::in(UserPreference::SHELL_SIDEBAR_SURFACES),
            ],
            'shell_colors.main' => [
                'sometimes',
                'string',
                Rule::in(UserPreference::SHELL_MAIN_SURFACES),
            ],
        ];
    }
}
