<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $remaining = (int) DB::table('workshop_enrollments')->count();
        if ($remaining > 0) {
            throw new \RuntimeException(
                "No se puede recortar workshop_enrollments: hay {$remaining} filas. Haz backfill antes de migrar."
            );
        }

        Schema::table('workshop_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grade_level_id');
            $table->dropColumn('group_number');
            $table->string('source', 32)->default('manual')->after('academic_year_id');
            $table->string('status', 32)->default('assigned')->after('source');
            $table->foreignId('assigned_by')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->text('notes')->nullable()->after('assigned_by');
            
            $table->unique(['student_id', 'academic_year_id'], 'workshop_enrollments_student_year_unique');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_enrollments', function (Blueprint $table) {
            $table->dropUnique('workshop_enrollments_student_year_unique');
            $table->dropConstrainedForeignId('assigned_by');
            $table->dropColumn(['source', 'status', 'notes']);
            $table->foreignId('grade_level_id')->nullable()->constrained('grade_levels');
            $table->string('group_number')->nullable();
        });
    }
};
