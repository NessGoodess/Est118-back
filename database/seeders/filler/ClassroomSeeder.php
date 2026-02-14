<?php

namespace Database\Seeders\filler;

use App\Models\Classroom;
use Illuminate\Database\Seeder;

class ClassroomSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $classrooms = [
            ['name' => 'Aula 1', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 2', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 3', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 4', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 5', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 6', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 7', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 8', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 9', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 10', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 11', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 12', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 13', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 14', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 15', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 16', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 17', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 18', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 19', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 20', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 21', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 22', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 23', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula 24', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Cancha de la Institución', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula de Medios', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula de Taller de Dibujo', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula de Taller de Corte y Confección', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula de Taller de Informática', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
            ['name' => 'Aula de Taller de Maquinas Herramienta y Sistemas de Control', 'location' => null, 'capacity' => null, 'features' => null, 'is_active' => 1],
        ];

        foreach ($classrooms as $classroom) {
            Classroom::create($classroom);
        }
    }
}
