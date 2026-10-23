<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guardian_student', function (Blueprint $table) {
            $table->boolean('is_primary')->default(false)->after('relationship');
            $table->unsignedTinyInteger('sort_order')->default(0)->after('is_primary');
            $table->index(['student_id', 'sort_order'], 'guardian_student_student_sort_index');
        });

        // First linked tutor per student becomes primary; others keep order by id.
        $studentIds = DB::table('guardian_student')->distinct()->pluck('student_id');
        foreach ($studentIds as $studentId) {
            $rows = DB::table('guardian_student')
                ->where('student_id', $studentId)
                ->orderBy('id')
                ->get(['id']);

            foreach ($rows as $index => $row) {
                DB::table('guardian_student')->where('id', $row->id)->update([
                    'is_primary' => $index === 0,
                    'sort_order' => $index,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('guardian_student', function (Blueprint $table) {
            $table->dropIndex('guardian_student_student_sort_index');
            $table->dropColumn(['is_primary', 'sort_order']);
        });
    }
};
