<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('general_attendances', function (Blueprint $table) {
            $table->timestamp('entry_at')->nullable()->after('scanned_at');
            $table->timestamp('exit_at')->nullable()->after('entry_at');
        });

        // Migrate existing scanned_at to entry_at
        DB::table('general_attendances')->whereNotNull('scanned_at')->update([
            'entry_at' => DB::raw('scanned_at'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('general_attendances', function (Blueprint $table) {
            $table->dropColumn(['entry_at', 'exit_at']);
        });
    }
};
