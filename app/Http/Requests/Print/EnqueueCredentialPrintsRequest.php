<?php

namespace App\Http\Requests\Print;

use Illuminate\Foundation\Http\FormRequest;

class EnqueueCredentialPrintsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'credential_print_ids' => ['required', 'array', 'min:1'],
            'credential_print_ids.*' => ['integer', 'distinct', 'exists:credential_prints,id'],
            'side' => ['required', 'string', 'in:front,back'],
            'printer_id' => ['sometimes', 'string', 'max:64'],
        ];
    }
}
