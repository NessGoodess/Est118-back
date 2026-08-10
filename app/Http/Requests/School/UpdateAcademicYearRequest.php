<?php

namespace App\Http\Requests\School;

use App\Models\AcademicYear;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class UpdateAcademicYearRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'starts_on' => ['sometimes', 'date'],
            'ends_on' => ['sometimes', 'date'],
            'year_start' => ['sometimes', 'digits:4'],
            'year_end' => ['sometimes', 'digits:4'],
            'description' => ['sometimes', 'string', 'max:100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            /** @var AcademicYear $year */
            $year = $this->route('academicYear');

            $startsOn = (string) ($this->input('starts_on') ?? $year->starts_on?->toDateString());
            $endsOn = (string) ($this->input('ends_on') ?? $year->ends_on?->toDateString());

            if ($startsOn === '' || $endsOn === '') {
                return;
            }

            if (strtotime($endsOn) <= strtotime($startsOn)) {
                $validator->errors()->add(
                    'ends_on',
                    'La fecha de fin debe ser posterior a la de inicio.'
                );

                return;
            }

            if (AcademicYear::rangesOverlap($startsOn, $endsOn, $year->id)) {
                $validator->errors()->add(
                    'starts_on',
                    'El rango de fechas se solapa con otro ciclo escolar.'
                );
            }
        });
    }
}
