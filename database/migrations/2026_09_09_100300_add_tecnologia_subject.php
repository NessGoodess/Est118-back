<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('subjects')
            ->where('code', 'TECNOLOGIA')
            ->orWhereRaw('LOWER(name) = ?', ['tecnología'])
            ->orWhereRaw('LOWER(name) = ?', ['tecnologia'])
            ->exists();

        if ($exists) {
            DB::table('subjects')
                ->where(function ($query) {
                    $query->whereRaw('LOWER(name) = ?', ['tecnología'])
                        ->orWhereRaw('LOWER(name) = ?', ['tecnologia']);
                })
                ->where(function ($query) {
                    $query->whereNull('code')->orWhere('code', '');
                })
                ->update(['code' => 'TECNOLOGIA']);

            return;
        }

        DB::table('subjects')->insert([
            'name' => 'Tecnología',
            'code' => 'TECNOLOGIA',
            'description' => 'Slot de aula para desdoble de talleres.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        DB::table('subjects')->where('code', 'TECNOLOGIA')->delete();
    }
};
