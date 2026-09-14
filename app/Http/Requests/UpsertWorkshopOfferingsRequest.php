<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpsertWorkshopOfferingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'academic_year_id' => ['required', 'integer', 'min:1'],
            'offerings' => ['required', 'array', 'min:1'],
            'offerings.*.workshop_id' => ['required', 'integer', 'min:1'],
            'offerings.*.capacity' => ['nullable', 'integer', 'min:0'],
            'offerings.*.is_open_for_intake' => ['sometimes', 'boolean'],
        ];
    }
}
