<?php

namespace App\Http\Requests\Print;

use Illuminate\Foundation\Http\FormRequest;

class DiscardCredentialPrintsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'credential_print_ids' => ['sometimes', 'array', 'min:1'],
            'credential_print_ids.*' => ['integer', 'distinct', 'exists:credential_prints,id'],
            'batch_uuid' => ['sometimes', 'uuid'],
            'reason' => ['sometimes', 'string', 'max:255'],
        ];
    }
}
