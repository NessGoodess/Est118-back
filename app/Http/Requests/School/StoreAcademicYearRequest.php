<?php

namespace App\Http\Requests\School;

use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after:starts_on'],
            'year_start' => ['sometimes', 'digits:4'],
            'year_end' => ['sometimes', 'digits:4', 'gt:year_start'],
            'description' => ['sometimes', 'string', 'max:100'],
            'generate_class_groups' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $startsOn = (string) $this->input('starts_on');
            $endsOn = (string) $this->input('ends_on');

            if (AcademicYear::rangesOverlap($startsOn, $endsOn)) {
                $validator->errors()->add(
                    'starts_on',
                    'El rango de fechas se solapa con otro ciclo escolar.'
                );
            }
        });
    }

    public function messages(): array
    {
        return [
            'ends_on.after' => 'La fecha de fin debe ser posterior a la de inicio.',
        ];
    }
}
