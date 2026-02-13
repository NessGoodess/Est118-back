<?php

namespace Database\Seeders\filler;

use App\Models\AcademicYear;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class AcademicYearSeeder extends Seeder
{
    /** 
     * Run the database seeds.
     */
    public function run(): void
    {
         AcademicYear::create([
            'year_start'  => 2025,
            'year_end'    => 2026,
            'description' => 'Año escolar 2025-2026',
            'is_active'   => 1,
        ]);
    }
}
