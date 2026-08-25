<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertAtMostOne(
            table: 'admission_cycles',
            conditionSql: "status = 'active'",
            label: 'periodos de preinscripción ACTIVE'
        );
        $this->assertAtMostOne(
            table: 'academic_years',
            conditionSql: 'is_active = 1',
            label: 'ciclos escolares is_active=1'
        );

        Schema::table('admission_cycles', function (Blueprint $table) {
            $table->unsignedTinyInteger('active_flag')
                ->nullable()
                ->storedAs("CASE WHEN status = 'active' THEN 1 ELSE NULL END")
                ->unique();
        });

        Schema::table('academic_years', function (Blueprint $table) {
            $table->unsignedTinyInteger('active_flag')
                ->nullable()
                ->storedAs('CASE WHEN is_active = 1 THEN 1 ELSE NULL END')
                ->unique();
        });
    }

    public function down(): void
    {
        Schema::table('admission_cycles', function (Blueprint $table) {
            $table->dropUnique(['active_flag']);
            $table->dropColumn('active_flag');
        });

        Schema::table('academic_years', function (Blueprint $table) {
            $table->dropUnique(['active_flag']);
            $table->dropColumn('active_flag');
        });
    }

    private function assertAtMostOne(string $table, string $conditionSql, string $label): void
    {
        $count = (int) DB::table($table)->whereRaw($conditionSql)->count();
        if ($count > 1) {
            throw new \RuntimeException(
                "No se puede crear active_flag único: hay {$count} {$label}. Deja solo uno antes de migrar."
            );
        }
    }
};
