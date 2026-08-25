<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $this->assertNoDuplicates('pre_enrollments', ['curp']);
        $this->assertNoDuplicates('students', ['profile_id']);
        $this->assertNoDuplicates('guardians', ['profile_id']);
        $this->assertNoDuplicates('guardian_student', ['student_id', 'guardian_id']);
        $this->assertNoDuplicates('enrollments', ['student_id', 'academic_year_id']);

        if (Schema::hasIndex('pre_enrollments', 'pre_enrollments_curp_index')) {
            Schema::table('pre_enrollments', function (Blueprint $table) {
                $table->dropIndex('pre_enrollments_curp_index');
            });
        }

        $this->addUniqueIfMissing('pre_enrollments', 'curp', 'pre_enrollments_curp_unique');
        $this->addUniqueIfMissing('students', 'profile_id', 'students_profile_id_unique');
        $this->addUniqueIfMissing('guardians', 'profile_id', 'guardians_profile_id_unique');
        $this->addUniqueIfMissing('guardian_student', ['student_id', 'guardian_id'], 'guardian_student_pair_unique');
        $this->addUniqueIfMissing('enrollments', ['student_id', 'academic_year_id'], 'enrollments_student_year_unique');
    }

    public function down(): void
    {
        $this->dropUniqueIfExists('pre_enrollments', 'pre_enrollments_curp_unique', 'curp', 'pre_enrollments_curp_index');
        $this->dropUniqueIfExists('students', 'students_profile_id_unique', 'profile_id', 'students_profile_id_index');
        $this->dropUniqueIfExists('guardians', 'guardians_profile_id_unique', 'profile_id', 'guardians_profile_id_index');
        $this->dropUniqueIfExists('guardian_student', 'guardian_student_pair_unique', ['student_id', 'guardian_id'], 'guardian_student_pair_index');
        $this->dropUniqueIfExists('enrollments', 'enrollments_student_year_unique', ['student_id', 'academic_year_id'], 'enrollments_student_year_index');
    }

    /**
     * Abort with a clear message before MySQL rejects the unique index.
     *
     * @param  array<int, string>  $columns
     */
    private function assertNoDuplicates(string $tableName, array $columns): void
    {
        $select = implode(', ', $columns);
        $groupBy = implode(', ', $columns);

        $duplicates = DB::table($tableName)
            ->selectRaw("{$select}, COUNT(*) as duplicate_count")
            ->groupBy($columns)
            ->havingRaw('COUNT(*) > 1')
            ->orderByDesc('duplicate_count')
            ->limit(10)
            ->get();

        if ($duplicates->isEmpty()) {
            return;
        }

        $samples = $duplicates->map(function ($row) use ($columns) {
            $parts = [];
            foreach ($columns as $column) {
                $parts[] = "{$column}=".var_export($row->{$column}, true);
            }

            return implode(', ', $parts)." (x{$row->duplicate_count})";
        })->implode('; ');

        throw new \RuntimeException(
            "No se puede crear el índice único en {$tableName}({$select}): hay duplicados. "
            ."Corrígelos antes de migrar. Ejemplos: {$samples}"
        );
    }

    /**
     * @param  string|array<int, string>  $columns
     */
    private function addUniqueIfMissing(string $tableName, string|array $columns, string $indexName): void
    {
        if (Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        Schema::table($tableName, function (Blueprint $table) use ($columns, $indexName) {
            $table->unique($columns, $indexName);
        });
    }

    /**
     * MySQL refuses to drop an index a foreign key relies on, so the plain
     * index has to exist before the unique one goes away.
     *
     * @param  string|array<int, string>  $columns
     */
    private function dropUniqueIfExists(string $tableName, string $indexName, string|array $columns, string $fallbackIndexName): void
    {
        if (! Schema::hasIndex($tableName, $indexName)) {
            return;
        }

        if (! Schema::hasIndex($tableName, $fallbackIndexName)) {
            Schema::table($tableName, function (Blueprint $table) use ($columns, $fallbackIndexName) {
                $table->index($columns, $fallbackIndexName);
            });
        }

        Schema::table($tableName, function (Blueprint $table) use ($indexName) {
            $table->dropUnique($indexName);
        });
    }
};
