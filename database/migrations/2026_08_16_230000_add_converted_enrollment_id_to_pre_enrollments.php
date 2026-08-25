<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_enrollments', function (Blueprint $table) {
            $table->foreignId('converted_enrollment_id')
                ->nullable()
                ->after('converted_student_id')
                ->constrained('enrollments')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('pre_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_enrollment_id');
        });
    }
};
