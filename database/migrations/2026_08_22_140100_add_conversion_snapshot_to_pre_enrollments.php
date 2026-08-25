<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_enrollments', function (Blueprint $table) {
            $table->json('conversion_options')->nullable()->after('converted_at');
            $table->json('conversion_policy_snapshot')->nullable()->after('conversion_options');
        });
    }

    public function down(): void
    {
        Schema::table('pre_enrollments', function (Blueprint $table) {
            $table->dropColumn(['conversion_options', 'conversion_policy_snapshot']);
        });
    }
};
