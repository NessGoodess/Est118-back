<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshops', function (Blueprint $table) {
            $table->boolean('is_internal')->default(false)->after('is_active');
        });

        $workshopId = DB::table('workshops')->where('code', 'OFIMATICA')->value('id');
        if (! $workshopId) {
            $workshopId = DB::table('workshops')->insertGetId([
                'name' => 'Ofimática',
                'code' => 'OFIMATICA',
                'description' => 'Taller interno. No se ofrece en la preinscripción; es la última opción de asignación.',
                'is_active' => true,
                'is_internal' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            DB::table('workshops')->where('id', $workshopId)->update([
                'name' => 'Ofimática',
                'is_active' => true,
                'is_internal' => true,
                'updated_at' => now(),
            ]);
        }

        $yearIds = DB::table('academic_years')->pluck('id');
        foreach ($yearIds as $yearId) {
            $offering = DB::table('workshop_offerings')
                ->where('workshop_id', $workshopId)
                ->where('academic_year_id', $yearId)
                ->first();

            if ($offering) {
                DB::table('workshop_offerings')->where('id', $offering->id)->update([
                    'is_open_for_intake' => false,
                    'updated_at' => now(),
                ]);

                continue;
            }

            DB::table('workshop_offerings')->insert([
                'workshop_id' => $workshopId,
                'academic_year_id' => $yearId,
                'capacity' => null,
                'is_open_for_intake' => false,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $workshopId = DB::table('workshops')->where('code', 'OFIMATICA')->value('id');
        if ($workshopId) {
            DB::table('workshop_offerings')->where('workshop_id', $workshopId)->delete();
            DB::table('workshops')->where('id', $workshopId)->delete();
        }

        Schema::table('workshops', function (Blueprint $table) {
            $table->dropColumn('is_internal');
        });
    }
};
