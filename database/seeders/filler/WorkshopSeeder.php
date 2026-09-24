<?php

namespace Database\Seeders\filler;

use App\Enums\AdmissionWorkshop;
use App\Models\AcademicYear;
use App\Models\Workshop;
use App\Models\WorkshopOffering;
use Illuminate\Database\Seeder;

class WorkshopSeeder extends Seeder
{
    public function run(): void
    {
        foreach (AdmissionWorkshop::cases() as $workshop) {
            Workshop::query()->updateOrCreate(
                ['code' => $workshop->code()],
                [
                    'name' => $workshop->value,
                    'description' => null,
                    'is_active' => true,
                    'is_internal' => false,
                ]
            );
        }

        Workshop::query()->updateOrCreate(
            ['code' => Workshop::OFIMATICA_CODE],
            [
                'name' => 'Ofimática',
                'description' => 'Taller interno. No se ofrece en la preinscripción; es la última opción de asignación.',
                'is_active' => true,
                'is_internal' => true,
            ]
        );

        $year = AcademicYear::query()->where('is_active', true)->first()
            ?? AcademicYear::query()->orderByDesc('id')->first();

        if (! $year) {
            return;
        }

        foreach (Workshop::query()->where('is_active', true)->get() as $row) {
            WorkshopOffering::query()->updateOrCreate(
                [
                    'workshop_id' => $row->id,
                    'academic_year_id' => $year->id,
                ],
                [
                    'capacity' => null,
                    'is_open_for_intake' => ! $row->is_internal,
                ]
            );
        }
    }
}
