<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->repairPartialAttempt();

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
            $table->unsignedBigInteger('open_student_id')->nullable();
        });

        DB::table('credential_prints')
            ->whereNull('discarded_at')
            ->where(function ($q) {
                $q->where('front_status', 'pending')
                    ->orWhere(function ($inner) {
                        $inner->where('front_status', 'printed')
                            ->where('back_status', 'pending');
                    });
            })
            ->update(['open_student_id' => DB::raw('student_id')]);

        Schema::table('credential_prints', function (Blueprint $table) {
            $table->unique('open_student_id');
        });
    }

    public function down(): void
    {
        Schema::table('credential_prints', function (Blueprint $table) {
            $table->dropUnique(['open_student_id']);
            $table->dropColumn('open_student_id');
        });
    }

    /**
     * MySQL no puede usar una columna generada basada en student_id: intenta
     * copiar su llave foránea y falla con 1215. Si un intento anterior dejó
     * esa columna y quitó la llave, se corrige antes de crear la columna real.
     */
    private function repairPartialAttempt(): void
    {
        if (Schema::hasColumn('credential_prints', 'open_student_id')) {
            Schema::table('credential_prints', function (Blueprint $table) {
                $table->dropUnique(['open_student_id']);
                $table->dropColumn('open_student_id');
            });
        }

        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        $foreignKey = DB::selectOne(
            "SELECT CONSTRAINT_NAME
             FROM information_schema.TABLE_CONSTRAINTS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'credential_prints'
               AND CONSTRAINT_NAME = 'credential_prints_student_id_foreign'"
        );

        if ($foreignKey) {
            return;
        }

        Schema::table('credential_prints', function (Blueprint $table) {
            $table->foreign('student_id')->references('id')->on('students')->cascadeOnDelete();
        });
    }
};
