<?php

namespace App\Http\Requests\Nfc;

use App\Models\NfcAssignments;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreNfcAssignmentRequest extends FormRequest
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
            'student_id' => ['required', 'integer', 'exists:students,id'],
            'action' => ['required', 'string', Rule::in([
                NfcAssignments::ACTION_ASSIGN,
                NfcAssignments::ACTION_VERIFY,
                NfcAssignments::ACTION_REWRITE,
            ])],
        ];
    }
};
