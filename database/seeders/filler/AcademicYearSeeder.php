<?php

namespace Database\Seeders\filler;

use App\Models\AcademicYear;
use Illuminate\Database\Seeder;

class AcademicYearSeeder extends Seeder
{
    public function run(): void
    {
        AcademicYear::create([
            'year_start' => 2025,
            'year_end' => 2026,
            'starts_on' => '2025-08-01',
            'ends_on' => '2026-07-31',
            'description' => 'Año escolar 2025-2026',
            'is_active' => true,
        ]);
    }
}
