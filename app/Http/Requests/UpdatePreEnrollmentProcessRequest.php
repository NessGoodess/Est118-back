<?php

namespace App\Http\Requests;

use App\Enums\DocumentsStatus;
use App\Enums\PaymentStatus;
use App\Enums\PreEnrollmentStatus;
use Illuminate\Foundation\Http\FormRequest;

class UpdatePreEnrollmentProcessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', 'string', 'in:' . implode(',', array_map(fn ($c) => $c->value, PreEnrollmentStatus::cases()))],
            'documents_status' => ['sometimes', 'string', 'in:' . implode(',', array_map(fn ($c) => $c->value, DocumentsStatus::cases()))],
            'payment_status' => ['sometimes', 'string', 'in:' . implode(',', array_map(fn ($c) => $c->value, PaymentStatus::cases()))],
            'admission_exam_score' => ['sometimes', 'nullable', 'numeric', 'between:0,10'],
        ];
    }
}

