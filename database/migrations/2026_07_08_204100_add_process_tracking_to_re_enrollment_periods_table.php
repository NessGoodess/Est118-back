<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('re_enrollment_periods', function (Blueprint $table) {
            $table->timestamp('promotion_executed_at')->nullable()->after('finalized_at');
            $table->foreignId('promotion_executed_by')->nullable()->after('promotion_executed_at')->constrained('users')->nullOnDelete();
            $table->foreignId('finalized_by')->nullable()->after('promotion_executed_by')->constrained('users')->nullOnDelete();
            $table->json('last_promotion_summary')->nullable()->after('finalized_by');
        });
    }

    public function down(): void
    {
        Schema::table('re_enrollment_periods', function (Blueprint $table) {
            $table->dropForeign(['promotion_executed_by']);
            $table->dropForeign(['finalized_by']);
            $table->dropColumn(['promotion_executed_at', 'promotion_executed_by', 'finalized_by', 'last_promotion_summary']);
        });
    }
};
