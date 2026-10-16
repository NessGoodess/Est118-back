<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'teachers' => 'teachers_profile_id_unique',
        'staff' => 'staff_profile_id_unique',
    ];

    public function up(): void
    {
        foreach (array_keys(self::TABLES) as $tableName) {
            $this->assertNoDuplicates($tableName);
        }

        foreach (self::TABLES as $tableName => $indexName) {
            if (Schema::hasIndex($tableName, $indexName)) {
                continue;
            }
            Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                $table->unique('profile_id', $indexName);
            });
        }
    }

    /**
     * MySQL refuses to drop an index a foreign key relies on, so the plain
     * index has to exist before the unique one goes away.
     */
    public function down(): void
    {
        foreach (self::TABLES as $tableName => $indexName) {
            if (! Schema::hasIndex($tableName, $indexName)) {
                continue;
            }
            $fallback = "{$tableName}_profile_id_index";
            if (! Schema::hasIndex($tableName, $fallback)) {
                Schema::table($tableName, function (Blueprint $table) use ($fallback) {
                    $table->index('profile_id', $fallback);
                });
            }
            Schema::table($tableName, function (Blueprint $table) use ($indexName) {
                $table->dropUnique($indexName);
            });
        }
    }

    private function assertNoDuplicates(string $tableName): void
    {
        $duplicates = DB::table($tableName)
            ->selectRaw('profile_id, COUNT(*) as duplicate_count')
            ->groupBy('profile_id')
            ->havingRaw('COUNT(*) > 1')
            ->limit(10)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $samples = $duplicates
            ->map(fn ($row) => "profile_id={$row->profile_id} (x{$row->duplicate_count})")
            ->implode('; ');

        throw new \RuntimeException(
            "No se puede crear el índice único en {$tableName}(profile_id): hay duplicados. "
            ."Corrígelos antes de migrar. Ejemplos: {$samples}"
        );
    }
};
