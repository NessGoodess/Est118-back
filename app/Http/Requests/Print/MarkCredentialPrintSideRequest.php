<?php

namespace App\Http\Requests\Print;

use Illuminate\Foundation\Http\FormRequest;

class MarkCredentialPrintSideRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'credential_print_id' => ['required', 'integer', 'exists:credential_prints,id'],
            'side' => ['required', 'string', 'in:front,back'],
        ];
    }
}
