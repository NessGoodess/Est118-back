<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->string('admission_channel', 20)->nullable()->after('is_new_admission'); // campaign|late
            $table->string('placement_status', 20)->nullable()->after('admission_channel'); // provisional|placed
            $table->json('convert_exception_flags')->nullable()->after('placement_status');
            $table->timestamp('placed_at')->nullable()->after('convert_exception_flags');
        });
    }

    public function down(): void
    {
        Schema::table('enrollments', function (Blueprint $table) {
            $table->dropColumn([
                'admission_channel',
                'placement_status',
                'convert_exception_flags',
                'placed_at',
            ]);
        });
    }
};
