<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_enrollments', function (Blueprint $table) {
            $table->foreignId('converted_student_id')->nullable()->constrained('students')->nullOnDelete()->after('payment_status');
        });
    }

    public function down(): void
    {
        Schema::table('pre_enrollments', function (Blueprint $table) {
            $table->dropForeign(['converted_student_id']);
            $table->dropColumn('converted_student_id');
        });
    }
};
