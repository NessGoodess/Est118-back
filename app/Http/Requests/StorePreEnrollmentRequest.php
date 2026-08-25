<?php

namespace App\Http\Requests;

use App\Services\AdmissionIdempotencyService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class StorePreEnrollmentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Replay before CURP unique validation so retries with the same key
     * return the original folio instead of a 422.
     */
    protected function prepareForValidation(): void
    {
        $scope = $this->is('api/admissions/pre-enrollment')
            ? AdmissionIdempotencyService::SCOPE_PUBLIC_STORE
            : AdmissionIdempotencyService::SCOPE_ADMIN_STORE;

        $early = app(AdmissionIdempotencyService::class)->resolve($this, $scope);
        if ($early) {
            throw new HttpResponseException($early);
        }

        $merge = [];
        if ($this->has('applicantInfo.curp')) {
            $merge['applicantInfo'] = array_merge(
                (array) $this->input('applicantInfo', []),
                ['curp' => strtoupper(trim((string) $this->input('applicantInfo.curp')))]
            );
        }
        if ($this->has('guardianInfo.guardianCurp')) {
            $merge['guardianInfo'] = array_merge(
                $merge['guardianInfo'] ?? (array) $this->input('guardianInfo', []),
                ['guardianCurp' => strtoupper(trim((string) $this->input('guardianInfo.guardianCurp')))]
            );
        }
        if ($merge !== []) {
            $this->merge($merge);
        }
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email.contactEmail' => 'required|email|max:100',
            'applicantInfo.firstName' => 'required|string|max:100',
            'applicantInfo.lastName' => 'required|string|max:100',
            'applicantInfo.secondLastName' => 'nullable|string|max:100',
            'applicantInfo.curp' => 'required|string|unique:pre_enrollments,curp|size:18|regex:/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/',
            'applicantInfo.birthDate' => 'required|date',
            'applicantInfo.age' => 'required|integer|between:10,18',
            'applicantInfo.gender' => 'required|in:M,F,O',
            'applicantInfo.phone' => 'required|string|max:15',
            'applicantInfo.studentEmail' => 'required|email|max:100',
            'applicantInfo.placeOfBirth' => 'required|string|max:100',
            'academicInfo.previousSchool' => 'required|string|max:100',
            'academicInfo.currentAverage' => 'required|numeric|between:0,10',
            'academicInfo.hasSiblings' => 'required|boolean',
            'academicInfo.siblingsDetails' => 'required_if:academicInfo.hasSiblings,true|nullable|string|max:255',
            'addressInfo.streetType' => 'required|string|max:100',
            'addressInfo.streetName' => 'required|string|max:100',
            'addressInfo.houseNumber' => 'required|string|max:100',
            'addressInfo.unitNumber' => 'nullable|string|max:100',
            'addressInfo.neighborhoodType' => 'required|string|max:100',
            'addressInfo.neighborhoodName' => 'required|string|max:100',
            'addressInfo.postalCode' => 'required|string|max:5',
            'addressInfo.city' => 'required|string|max:100',
            'addressInfo.state' => 'required|string|max:100',
            'guardianInfo.guardianFirstName' => 'required|string|max:100',
            'guardianInfo.guardianLastName' => 'required|string|max:100',
            'guardianInfo.guardianSecondLastName' => 'nullable|string|max:100',
            'guardianInfo.guardianCurp' => 'required|string|size:18|regex:/^[A-Z]{4}\d{6}[HM][A-Z]{5}[A-Z0-9]\d$/',
            'guardianInfo.guardianPhone' => 'required|string|max:15',
            'guardianInfo.guardianRelationship' => 'required|string|max:100',
            'workshopSelect.workshopFirstChoice' => 'required|string|max:100',
            'workshopSelect.workshopSecondChoice' => 'required|string|max:100',
            'tuitionVoucher.hasSchoolVoucher' => 'required|boolean',
            'tuitionVoucher.schoolVoucherFolio' => 'exclude_if:tuitionVoucher.hasSchoolVoucher,false|required|string|max:100',
            '_idempotency_key' => 'sometimes|nullable|string|max:128',
        ];
    }

    public function messages(): array
    {
        return [
            'applicantInfo.curp.required' => 'La CURP es obligatoria.',
            'applicantInfo.curp.regex' => 'La CURP no es válida.',
            'applicantInfo.curp.unique' => 'Ya existe un registro con esta CURP.',
            'applicantInfo.curp.size' => 'La CURP debe tener 18 caracteres.',
            'guardianInfo.guardianCurp.regex' => 'La CURP del tutor no es válida.',
            'guardianInfo.guardianCurp.size' => 'La CURP del tutor debe tener 18 caracteres.',
        ];
    }
}
