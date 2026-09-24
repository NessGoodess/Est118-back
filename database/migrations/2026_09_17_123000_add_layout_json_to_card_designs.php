<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('card_designs', function (Blueprint $table) {
            $table->json('layout_json')->nullable();
        });

        $rows = DB::table('card_designs')->select('id', 'uuid')->get();
        foreach ($rows as $row) {
            $path = storage_path('app/card-templates/'.$row->uuid.'/layout.json');
            if (! is_file($path)) {
                continue;
            }
            $layout = json_decode((string) file_get_contents($path), true);
            if (! is_array($layout)) {
                continue;
            }
            DB::table('card_designs')->where('id', $row->id)->update([
                'layout_json' => json_encode($layout, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::table('card_designs', function (Blueprint $table) {
            $table->dropColumn('layout_json');
        });
    }
};
