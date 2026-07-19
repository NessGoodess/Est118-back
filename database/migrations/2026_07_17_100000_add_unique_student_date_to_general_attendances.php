<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('general_attendances', function (Blueprint $table) {
            $table->unique(
                ['student_id', 'date'],
                'general_attendances_student_date_unique'
            );
        });
    }

    public function down(): void
    {
        Schema::table('general_attendances', function (Blueprint $table) {
            $table->dropUnique('general_attendances_student_date_unique');
        });
    }
};
