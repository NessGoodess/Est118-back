<?php

namespace App\Http\Requests\Print;

use App\Enums\PrintBatchStrategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCredentialPrintsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['integer', 'distinct', 'exists:students,id'],
            'printer_id' => ['sometimes', 'string', 'max:64'],
            'template_key' => ['sometimes', 'string', 'max:64'],
            'strategy' => ['sometimes', 'string', Rule::in([
                PrintBatchStrategy::FrontsThenBacks->value,
                PrintBatchStrategy::PerCard->value,
                PrintBatchStrategy::BackOnly->value,
            ])],
        ];
    }
}
