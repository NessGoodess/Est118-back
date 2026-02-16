<?php

namespace App\Exports;

use App\Models\PreEnrollment;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class PreEnrollmentExport implements FromCollection, WithHeadings, WithMapping
{
    /**
    * @return \Illuminate\Support\Collection
    */
    public function collection()
    {
        return PreEnrollment::all();
    }
    
    /**
    * @return array
    */
    public function headings(): array
    {
        return [
            'Folio',
            'Email de Contacto',
            'Primer Nombre',
            'Apellido Paterno',
            'Apellido Materno',
            'CURP',
            'Fecha de Nacimiento',
            'Edad',
            'Género',
            'Teléfono',
            'Email del Estudiante',
            'Lugar de Nacimiento',
            'Escuela Anterior',
            'Promedio Actual',
            '¿Tiene Hermanos?',
            'Detalles de Hermanos',
            'Tipo de Calle',
            'Nombre de Calle',
            'Número Exterior',
            'Número Interior',
            'Tipo de Colonia',
            'Nombre de Colonia',
            'Código Postal',
            'Ciudad',
            'Estado',
            'Nombre del Tutor',
            'Apellido Paterno del Tutor',
            'Apellido Materno del Tutor',
            'CURP del Tutor',
            'Teléfono del Tutor',
            'Parentesco',
            'Primera Opción Taller',
            'Segunda Opción Taller',
            '¿Tiene Voucher?',
            'Folio del Voucher',
            'Fecha de Registro'
        ];
    }
    
    /**
    * @param mixed $preEnrollment
    * @return array
    */
    public function map($preEnrollment): array
    {
        return [
            $preEnrollment->folio,
            $preEnrollment->contact_email,
            $preEnrollment->first_name,
            $preEnrollment->last_name,
            $preEnrollment->second_last_name,
            $preEnrollment->curp,
            $preEnrollment->birth_date,
            $preEnrollment->age,
            $this->mapGender($preEnrollment->gender),
            $preEnrollment->phone,
            $preEnrollment->student_email,
            $preEnrollment->place_of_birth,
            $preEnrollment->previous_school,
            $preEnrollment->current_average,
            $this->mapBoolean($preEnrollment->has_siblings),
            $preEnrollment->siblings_details,
            $preEnrollment->street_type,
            $preEnrollment->street_name,
            $preEnrollment->house_number,
            $preEnrollment->unit_number,
            $preEnrollment->neighborhood_type,
            $preEnrollment->neighborhood_name,
            $preEnrollment->postal_code,
            $preEnrollment->city,
            $preEnrollment->state,
            $preEnrollment->guardian_first_name,
            $preEnrollment->guardian_last_name,
            $preEnrollment->guardian_second_last_name,
            $preEnrollment->guardian_curp,
            $preEnrollment->guardian_phone,
            $preEnrollment->guardian_relationship,
            $preEnrollment->workshop_first_choice,
            $preEnrollment->workshop_second_choice,
            $this->mapBoolean($preEnrollment->has_school_voucher),
            $preEnrollment->school_voucher_folio,
            $preEnrollment->created_at ? $preEnrollment->created_at->format('d/m/Y H:i:s') : null,
        ];
    }
    
    /**
    * Mapea el género a un formato más legible
    */
    private function mapGender($gender): string
    {
        return match($gender) {
            'M' => 'Masculino',
            'F' => 'Femenino',
            'O' => 'Otro',
            default => $gender ?? 'No especificado',
        };
    }
    
    /**
    * Mapea valores booleanos a Sí/No
    */
    private function mapBoolean($value): string
    {
        return $value ? 'Sí' : 'No';
    }
}