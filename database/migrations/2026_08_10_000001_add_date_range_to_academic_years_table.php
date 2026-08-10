<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->date('starts_on')->nullable()->after('year_end');
            $table->date('ends_on')->nullable()->after('starts_on');
        });

        $years = DB::table('academic_years')->select('id', 'year_start', 'year_end')->get();

        foreach ($years as $year) {
            DB::table('academic_years')->where('id', $year->id)->update([
                // Legacy resolver used Aug+ as the school-year boundary.
                'starts_on' => sprintf('%s-08-01', $year->year_start),
                'ends_on' => sprintf('%s-07-31', $year->year_end),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('academic_years', function (Blueprint $table) {
            $table->dropColumn(['starts_on', 'ends_on']);
        });
    }
};
