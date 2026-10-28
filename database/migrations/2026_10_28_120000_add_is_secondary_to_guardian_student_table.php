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
            $table->boolean('is_secondary')->default(false)->after('is_primary');
        });

        // One credential secondary per student: first non-primary by sort_order / id.
        $studentIds = DB::table('guardian_student')->distinct()->pluck('student_id');
        foreach ($studentIds as $studentId) {
            $secondaryId = DB::table('guardian_student')
                ->where('student_id', $studentId)
                ->where('is_primary', false)
                ->orderBy('sort_order')
                ->orderBy('id')
                ->value('id');

            if ($secondaryId) {
                DB::table('guardian_student')->where('id', $secondaryId)->update(['is_secondary' => true]);
            }
        }
    }

    public function down(): void
    {
        Schema::table('guardian_student', function (Blueprint $table) {
            $table->dropColumn('is_secondary');
        });
    }
};
