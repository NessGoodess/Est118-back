<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Normalize existing values so unique(curp) / unique(national_id) cover case/space variants.
        if (Schema::hasTable('pre_enrollments')) {
            DB::table('pre_enrollments')
                ->orderBy('id')
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $row) {
                        $normalized = strtoupper(trim((string) $row->curp));
                        if ($normalized !== (string) $row->curp) {
                            DB::table('pre_enrollments')->where('id', $row->id)->update([
                                'curp' => $normalized,
                            ]);
                        }

                        $guardian = strtoupper(trim((string) $row->guardian_curp));
                        if ($guardian !== (string) $row->guardian_curp) {
                            DB::table('pre_enrollments')->where('id', $row->id)->update([
                                'guardian_curp' => $guardian,
                            ]);
                        }
                    }
                });
        }

        if (Schema::hasTable('profiles')) {
            DB::table('profiles')
                ->whereNotNull('national_id')
                ->orderBy('id')
                ->chunkById(200, function ($rows) {
                    foreach ($rows as $row) {
                        $normalized = strtoupper(trim((string) $row->national_id));
                        if ($normalized !== (string) $row->national_id) {
                            DB::table('profiles')->where('id', $row->id)->update([
                                'national_id' => $normalized,
                            ]);
                        }
                    }
                });
        }

        $this->assertNoNormalizedDuplicates('pre_enrollments', 'curp');
        $this->assertNoNormalizedDuplicates('profiles', 'national_id');

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            Schema::table('pre_enrollments', function (Blueprint $table) {
                $table->string('curp_normalized', 18)
                    ->nullable()
                    ->storedAs('UPPER(TRIM(`curp`))');
            });
            Schema::table('pre_enrollments', function (Blueprint $table) {
                $table->unique('curp_normalized', 'pre_enrollments_curp_norm_unique');
            });
        } else {
            // SQLite (tests): plain column kept in sync by the model mutator.
            Schema::table('pre_enrollments', function (Blueprint $table) {
                $table->string('curp_normalized', 18)->nullable();
            });

            DB::table('pre_enrollments')->orderBy('id')->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('pre_enrollments')->where('id', $row->id)->update([
                        'curp_normalized' => strtoupper(trim((string) $row->curp)),
                    ]);
                }
            });

            Schema::table('pre_enrollments', function (Blueprint $table) {
                $table->unique('curp_normalized', 'pre_enrollments_curp_norm_unique');
            });
        }
    }

    public function down(): void
    {
        Schema::table('pre_enrollments', function (Blueprint $table) {
            if (Schema::hasIndex('pre_enrollments', 'pre_enrollments_curp_norm_unique')) {
                $table->dropUnique('pre_enrollments_curp_norm_unique');
            }
            if (Schema::hasColumn('pre_enrollments', 'curp_normalized')) {
                $table->dropColumn('curp_normalized');
            }
        });
    }

    private function assertNoNormalizedDuplicates(string $table, string $column): void
    {
        $duplicates = DB::table($table)
            ->selectRaw("UPPER(TRIM({$column})) as normalized_value, COUNT(*) as duplicate_count")
            ->whereNotNull($column)
            ->where($column, '!=', '')
            ->groupByRaw("UPPER(TRIM({$column}))")
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('duplicate_count')
            ->limit(10)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $samples = $duplicates->map(
            fn ($row) => "{$row->normalized_value} (x{$row->duplicate_count})"
        )->implode('; ');

        throw new \RuntimeException(
            "No se puede reforzar unicidad normalizada en {$table}.{$column}: hay duplicados. "
            ."Corrígelos antes de migrar. Ejemplos: {$samples}"
        );
    }
};
