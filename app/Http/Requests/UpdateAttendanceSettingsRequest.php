<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateAttendanceSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('edit general attendance') ?? false;
    }

    public function rules(): array
    {
        return [
            'timezone' => ['required', 'string', 'timezone:all'],
            'entry_time' => ['required', 'date_format:H:i'],
            'tolerance_minutes' => ['required', 'integer', 'min:0', 'max:180'],
            'exit_earliest' => ['required', 'date_format:H:i'],
            'entry_window_closes_at' => ['required', 'date_format:H:i', 'after:entry_time'],
        ];
    }

    public function messages(): array
    {
        return [
            'entry_window_closes_at.after' => 'El cierre de entrada debe ser posterior a la hora de entrada.',
        ];
    }
}
