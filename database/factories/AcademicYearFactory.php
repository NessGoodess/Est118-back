<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\AcademicYear>
 */
class AcademicYearFactory extends Factory
{
    public function definition(): array
    {
        $startYear = fake()->numberBetween(2024, 2030);

        return [
            'year_start' => (string) $startYear,
            'year_end' => (string) ($startYear + 1),
            'starts_on' => sprintf('%d-08-01', $startYear),
            'ends_on' => sprintf('%d-07-31', $startYear + 1),
            'description' => sprintf('Año escolar %d-%d', $startYear, $startYear + 1),
            'is_active' => false,
        ];
    }
}
