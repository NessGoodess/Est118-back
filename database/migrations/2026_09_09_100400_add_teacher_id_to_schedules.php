<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->foreignId('teacher_id')
                ->nullable()
                ->after('classroom_id')
                ->constrained('teachers')
                ->nullOnDelete();
            $table->unique(
                ['school_class_id', 'workshop_id', 'day', 'start_time'],
                'schedules_desdoble_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropUnique('schedules_desdoble_unique');
            $table->dropConstrainedForeignId('teacher_id');
        });
    }
};
