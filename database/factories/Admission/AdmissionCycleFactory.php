<?php

namespace Database\Factories\Admission;

use App\Enums\AdmissionCycleStatus;
use App\Models\Admission\AdmissionCycle;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AdmissionCycle>
 */
class AdmissionCycleFactory extends Factory
{
    protected $model = AdmissionCycle::class;

    public function definition(): array
    {
        $year = $this->faker->numberBetween(2025, 2030);

        return [
            'name' => "Preinscripciones {$year}-".($year + 1),
            'start_at' => now()->subWeek(),
            'end_at' => now()->addMonths(2),
            'status' => AdmissionCycleStatus::DRAFT,
            'last_folio_number' => 0,
            'created_by' => User::query()->value('id') ?? User::factory(),
            'closed_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'status' => AdmissionCycleStatus::ACTIVE,
            'start_at' => now()->subWeek(),
            'end_at' => now()->addMonths(2),
            'closed_at' => null,
        ]);
    }
}
