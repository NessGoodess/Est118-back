<?php

use App\Enums\PromotionResult;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->boolean('is_new_admission')->default(false)->after('status');
            $table->boolean('is_approved')->nullable()->after('is_new_admission');
            $table->enum('promotion_result', PromotionResult::cases())->nullable()->after('is_approved');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn(['is_new_admission', 'is_approved', 'promotion_result']);
        });
    }
};
