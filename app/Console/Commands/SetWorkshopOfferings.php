<?php

namespace App\Console\Commands;

use App\Models\AcademicYear;
use App\Models\Workshop;
use App\Models\WorkshopOffering;
use Illuminate\Console\Command;

class SetWorkshopOfferings extends Command
{
    protected $signature = 'workshop-offerings:set
                            {--year= : ID del ciclo escolar}
                            {--INFORMATICA= : Cupo Informática}
                            {--DISENO= : Cupo Diseño Industrial}
                            {--CONFECCION= : Cupo Confección}
                            {--MAQUINAS= : Cupo Máquinas}';

    protected $description = 'Fija el cupo de talleres del ciclo (enteros). Omite un código para no tocarlo.';

    public function handle(): int
    {
        $yearId = (int) $this->option('year');
        if ($yearId <= 0) {
            $yearId = (int) AcademicYear::query()->where('is_active', true)->value('id');
        }
        if ($yearId <= 0) {
            $this->error('Indica --year o activa un ciclo escolar.');

            return self::FAILURE;
        }

        $map = [
            'INFORMATICA' => $this->option('INFORMATICA'),
            'DISENO' => $this->option('DISENO'),
            'CONFECCION' => $this->option('CONFECCION'),
            'MAQUINAS' => $this->option('MAQUINAS'),
        ];

        $updated = 0;
        foreach ($map as $code => $raw) {
            if ($raw === null || $raw === false || $raw === '') {
                continue;
            }
            $workshop = Workshop::query()->where('code', $code)->first();
            if (! $workshop) {
                $this->warn("No existe el taller {$code}.");

                continue;
            }
            WorkshopOffering::query()->updateOrCreate(
                [
                    'workshop_id' => $workshop->id,
                    'academic_year_id' => $yearId,
                ],
                [
                    'capacity' => (int) $raw,
                    'is_open_for_intake' => true,
                ]
            );
            $this->line("{$code} = ".(int) $raw);
            $updated++;
        }

        if ($updated === 0) {
            $this->warn('No se actualizó ningún cupo. Pasa --INFORMATICA=N --DISENO=N --CONFECCION=N --MAQUINAS=N');

            return self::FAILURE;
        }

        $this->info("Cupos actualizados en ciclo {$yearId}: {$updated}.");

        return self::SUCCESS;
    }
}
