<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_enrollments', function (Blueprint $table) {
            $table->foreignId('reviewed_by')
                ->nullable()
                ->after('converted_enrollment_id')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            $table->foreignId('converted_by')
                ->nullable()
                ->after('reviewed_at')
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('converted_at')->nullable()->after('converted_by');
        });
    }

    public function down(): void
    {
        Schema::table('pre_enrollments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('reviewed_by');
            $table->dropColumn('reviewed_at');
            $table->dropConstrainedForeignId('converted_by');
            $table->dropColumn('converted_at');
        });
    }
};
