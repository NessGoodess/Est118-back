<?php

namespace Database\Seeders;

use App\Models\GradeLevel;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class GradeLevelSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $grades = [
            ['name' => '1°', 'description' => 'Primer Grado',  'is_active' => 1],
            ['name' => '2°', 'description' => 'Segundo Grado', 'is_active' => 1],
            ['name' => '3°', 'description' => 'Tercer Grado',  'is_active' => 1],
        ];

        foreach ($grades as $grade) {
            GradeLevel::create($grade);
        }
    }
}
