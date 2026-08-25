<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $extra = DB::table('admission_intake_settings')->count();
        if ($extra > 1) {
            $keepId = DB::table('admission_intake_settings')->orderBy('id')->value('id');
            DB::table('admission_intake_settings')->where('id', '!=', $keepId)->delete();
        }

        Schema::table('admission_intake_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('singleton_key')->default(1)->after('id');
        });

        // Backfill then enforce uniqueness (one school-wide policy row).
        DB::table('admission_intake_settings')->update(['singleton_key' => 1]);

        $dupes = DB::table('admission_intake_settings')
            ->select('singleton_key')
            ->groupBy('singleton_key')
            ->havingRaw('COUNT(*) > 1')
            ->exists();

        if ($dupes) {
            throw new \RuntimeException(
                'No se puede crear el singleton de admission_intake_settings: quedan filas duplicadas.'
            );
        }

        Schema::table('admission_intake_settings', function (Blueprint $table) {
            $table->unique('singleton_key', 'admission_intake_settings_singleton_unique');
        });
    }

    public function down(): void
    {
        Schema::table('admission_intake_settings', function (Blueprint $table) {
            $table->dropUnique('admission_intake_settings_singleton_unique');
            $table->dropColumn('singleton_key');
        });
    }
};
