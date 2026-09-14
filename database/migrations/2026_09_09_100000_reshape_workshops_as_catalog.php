<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $remaining = (int) DB::table('workshops')->count();
        if ($remaining > 0) {
            throw new \RuntimeException(
                "No se puede recortar workshops: hay {$remaining} filas. Haz backfill o vacía el catálogo antes de migrar."
            );
        }

        Schema::table('workshops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('teacher_id');
            $table->dropConstrainedForeignId('academic_year_id');
            $table->dropConstrainedForeignId('classroom_id');
            $table->dropColumn('capacity');
        });
    }

    public function down(): void
    {
        Schema::table('workshops', function (Blueprint $table) {
            $table->foreignId('teacher_id')->nullable()->constrained('teachers')->nullOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->foreignId('classroom_id')->nullable()->constrained('classrooms')->nullOnDelete();
            $table->string('capacity')->nullable();
        });
    }
};
