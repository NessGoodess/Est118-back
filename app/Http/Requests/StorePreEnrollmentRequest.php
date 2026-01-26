<?php

namespace App\Http\Requests;

use App\Enums\PreEnrollmentStatus;
use Illuminate\Foundation\Http\FormRequest;

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

        ];
    }
    //mensajes de validacion
    public function messages(): array
    {
        return [
            'email.contactEmail.required' => 'El correo electrónico es obligatorio.',
            'applicantInfo.firstName.required' => 'El nombre es obligatorio.',
            'applicantInfo.lastName.required' => 'El apellido es obligatorio.',
            'applicantInfo.secondLastName.required' => 'El apellido materno es obligatorio.',
            'applicantInfo.curp.required' => 'El CURP es obligatorio.',
            'applicantInfo.birthDate.required' => 'La fecha de nacimiento es obligatoria.',
            'applicantInfo.age.required' => 'La edad es obligatoria.',
            'applicantInfo.gender.required' => 'El género es obligatorio.',
            'applicantInfo.phone.required' => 'El teléfono es obligatorio.',
            'applicantInfo.studentEmail.required' => 'El correo electrónico del estudiante es obligatorio.',
            'applicantInfo.placeOfBirth.required' => 'El lugar de nacimiento es obligatorio.',
            'academicInfo.previousSchool.required' => 'La escuela anterior es obligatoria.',
            'academicInfo.currentAverage.required' => 'El promedio actual es obligatorio.',
            'academicInfo.hasSiblings.required' => 'El campo de hermanos es obligatorio.',
            'academicInfo.siblingsDetails.required_if' => 'El campo de detalles de hermanos es obligatorio si hay hermanos.',
            'addressInfo.streetType.required' => 'El tipo de calle es obligatorio.',
            'addressInfo.streetName.required' => 'El nombre de la calle es obligatorio.',
            'addressInfo.houseNumber.required' => 'El número de la casa es obligatorio.',
            'addressInfo.unitNumber.required' => 'El número de unidad es obligatorio.',
            'addressInfo.neighborhoodType.required' => 'El tipo de vecindario es obligatorio.',
            'addressInfo.neighborhoodName.required' => 'El nombre del vecindario es obligatorio.',
            'addressInfo.postalCode.required' => 'El código postal es obligatorio.',
            'addressInfo.city.required' => 'La ciudad es obligatoria.',
            'addressInfo.state.required' => 'El estado es obligatorio.',
            'guardianInfo.guardianFirstName.required' => 'El nombre del tutor es obligatorio.',
            'guardianInfo.guardianLastName.required' => 'El apellido del tutor es obligatorio.',
            'guardianInfo.guardianSecondLastName.required' => 'El apellido materno del tutor es obligatorio.',
            'guardianInfo.guardianCurp.required' => 'El CURP del tutor es obligatorio.',
            'guardianInfo.guardianPhone.required' => 'El teléfono del tutor es obligatorio.',
            'guardianInfo.guardianRelationship.required' => 'La relación con el tutor es obligatoria.',
            'workshopSelect.workshopFirstChoice.required' => 'El primer workshop es obligatorio.',
            'workshopSelect.workshopSecondChoice.required' => 'El segundo workshop es obligatorio.',
            'tuitionVoucher.hasSchoolVoucher.required' => 'El campo de voucher escolar es obligatorio.',
            'tuitionVoucher.schoolVoucherFolio.required_if' => 'El folio del voucher escolar es obligatorio si hay voucher escolar.',
        ];
    }
}
