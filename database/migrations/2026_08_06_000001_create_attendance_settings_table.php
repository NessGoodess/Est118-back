<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Singleton school-wide NFC attendance schedule (editable from web).
 * config/attendance.php remains the default / fallback.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_settings', function (Blueprint $table) {
            $table->id();
            $table->string('timezone', 64)->default('America/Mexico_City');
            $table->string('entry_time', 5); 
            $table->unsignedSmallInteger('tolerance_minutes')->default(10);
            $table->string('exit_earliest', 5);
            $table->string('entry_window_closes_at', 5);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        DB::table('attendance_settings')->insert([
            'timezone' => config('attendance.timezone', 'America/Mexico_City'),
            'entry_time' => config('attendance.entry_time', '07:00'),
            'tolerance_minutes' => (int) config('attendance.tolerance_minutes', 10),
            'exit_earliest' => config('attendance.exit_earliest', '13:30'),
            'entry_window_closes_at' => config('attendance.entry_window_closes_at', '12:00'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_settings');
    }
};
