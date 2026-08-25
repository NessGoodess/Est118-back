<?php

namespace Database\Seeders;

use App\Enums\AdmissionCycleStatus;
use App\Models\Admission\AdmissionCycle;
use App\Models\PreEnrollment;
use Illuminate\Database\Seeder;

class PreEnrollmentSeeder extends Seeder
{
    public function run(): void
    {
        $cycle = AdmissionCycle::query()
            ->where('status', AdmissionCycleStatus::ACTIVE)
            ->latest('id')
            ->first();

        if (! $cycle) {
            $userId = \App\Models\User::query()->value('id');
            if (! $userId) {
                $this->command?->error('No hay usuarios. Crea un usuario antes de sembrar preinscripciones.');

                return;
            }

            $cycle = AdmissionCycle::factory()->active()->create([
                'name' => 'Preinscripciones seed '.now()->year,
                'created_by' => $userId,
            ]);
        }

        $this->command?->info("Sembrando 200 preinscripciones en ciclo #{$cycle->id} ({$cycle->name}).");

        PreEnrollment::factory()->count(100)->readyToConvert()->for($cycle, 'admission_cycle')->create();
        PreEnrollment::factory()->count(40)->inReview()->for($cycle, 'admission_cycle')->create();
        PreEnrollment::factory()->count(30)->docsCompletePaymentPending()->for($cycle, 'admission_cycle')->create();
        PreEnrollment::factory()->count(20)->for($cycle, 'admission_cycle')->create();
        PreEnrollment::factory()->count(10)->rejected()->for($cycle, 'admission_cycle')->create();
    }
}
