<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicates = DB::table('credential_prints')
            ->select('student_id')
            ->whereNull('discarded_at')
            ->where(function ($q) {
                $q->where('front_status', 'pending')
                    ->orWhere(function ($inner) {
                        $inner->where('front_status', 'printed')
                            ->where('back_status', 'pending');
                    });
            })
            ->groupBy('student_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();

        if ($duplicates > 0) {
            throw new \RuntimeException(
                "No se puede crear open_student_id único: {$duplicates} alumno(s) tienen tarjetas abiertas duplicadas. Corre php artisan credential-prints:dedupe-open."
            );
        }

        Schema::table('credential_prints', function (Blueprint $table) {
            $table->unsignedBigInteger('open_student_id')
                ->nullable()
                ->storedAs(
                    "CASE WHEN discarded_at IS NULL AND (front_status = 'pending' OR (front_status = 'printed' AND back_status = 'pending')) THEN student_id END"
                )
                ->unique();
        });
    }

    public function down(): void
    {
        Schema::table('credential_prints', function (Blueprint $table) {
            $table->dropUnique(['open_student_id']);
            $table->dropColumn('open_student_id');
        });
    }
};
