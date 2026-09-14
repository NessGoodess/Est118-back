<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('class_groups')
            ->selectRaw('academic_year_id, grade_level_id, name, COUNT(*) as duplicate_count')
            ->groupBy('academic_year_id', 'grade_level_id', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('duplicate_count')
            ->limit(10)
            ->get();

        if ($duplicates->isNotEmpty()) {
            $samples = $duplicates->map(function ($row) {
                return "year={$row->academic_year_id}, grade={$row->grade_level_id}, name={$row->name} (x{$row->duplicate_count})";
            })->implode('; ');

            throw new \RuntimeException(
                "No se puede crear unique en class_groups(academic_year_id, grade_level_id, name): hay duplicados. Ejemplos: {$samples}"
            );
        }

        Schema::table('class_groups', function (Blueprint $table) {
            $table->unique(
                ['academic_year_id', 'grade_level_id', 'name'],
                'class_groups_year_grade_name_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('class_groups', function (Blueprint $table) {
            $table->dropUnique('class_groups_year_grade_name_unique');
        });
    }
};
