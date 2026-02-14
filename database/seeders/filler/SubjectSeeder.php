<?php

namespace Database\Seeders\filler;

use App\Models\Subject;
use Illuminate\Database\Seeder;

class SubjectSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $subjects = [
            ['name' => 'Español', 'code' => null, 'description' => null],
            ['name' => 'Socioemocional', 'code' => null, 'description' => null],
            ['name' => 'Matematicas', 'code' => null, 'description' => null],
            ['name' => 'Ciencias I (Biología)', 'code' => null, 'description' => null],
            ['name' => 'Ciencias III (Química)', 'code' => null, 'description' => null],
            ['name' => 'Ciencias II (Física)', 'code' => null, 'description' => null],
            ['name' => 'Historia', 'code' => null, 'description' => null],
            ['name' => 'Formación Cívica y Ética', 'code' => null, 'description' => null],
            ['name' => 'Geografía', 'code' => null, 'description' => null],
            ['name' => 'Inglés', 'code' => null, 'description' => null],
            ['name' => 'Artes', 'code' => null, 'description' => null],
            ['name' => 'Educación Física', 'code' => null, 'description' => null],
            ['name' => 'Diseño Industrial', 'code' => null, 'description' => null],
            ['name' => 'Confección del Vestido e Industria Textil', 'code' => null, 'description' => null],
            ['name' => 'Máquinas Herramienta y Sistemas de Control', 'code' => null, 'description' => null],
            ['name' => 'Informática', 'code' => null, 'description' => null],
        ];

        foreach ($subjects as $subject) {
            Subject::create($subject);
        }
    }
}
