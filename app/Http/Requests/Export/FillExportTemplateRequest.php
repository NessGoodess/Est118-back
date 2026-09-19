<?php

namespace App\Http\Requests\Export;

use App\Services\Export\FillExportTemplateService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class FillExportTemplateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $cellMap = $this->input('cell_map');
        if (is_array($cellMap)) {
            $normalized = [];
            foreach ($cellMap as $key => $value) {
                $normalized[$key] = is_string($value) ? strtoupper(trim($value)) : $value;
            }
            $this->merge(['cell_map' => $normalized]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'template_id' => [
                'required',
                'integer',
                Rule::exists('export_templates', 'id')->where(fn ($q) => $q->where('user_id', $this->user()->id)),
            ],
            'columns' => ['required', 'array', 'min:1'],
            'columns.*' => ['required', 'string', 'max:64'],
            'cell_map' => ['required', 'array'],
            'cell_map.*' => ['required', 'string', 'regex:'.FillExportTemplateService::CELL_PATTERN],
            'rows' => ['required', 'array', 'min:1'],
            'rows.*' => ['required', 'array'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'cell_map.*.regex' => 'Cada celda debe usar el formato A1 (letras mayúsculas + número).',
            'rows.min' => 'No hay filas para exportar.',
        ];
    }
}
