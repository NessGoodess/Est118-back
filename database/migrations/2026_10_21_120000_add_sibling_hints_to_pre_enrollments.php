<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_enrollments', function (Blueprint $table) {
            $table->string('sibling_hint_last_name', 100)->nullable()->after('siblings_details');
            $table->string('sibling_hint_second_last_name', 100)->nullable()->after('sibling_hint_last_name');
            $table->string('sibling_hint_first_name', 100)->nullable()->after('sibling_hint_second_last_name');
            $table->string('sibling_hint_grade', 20)->nullable()->after('sibling_hint_first_name');
            $table->string('sibling_hint_group', 20)->nullable()->after('sibling_hint_grade');
            $table->json('sibling_student_ids')->nullable()->after('sibling_hint_group');
        });
    }

    public function down(): void
    {
        Schema::table('pre_enrollments', function (Blueprint $table) {
            $table->dropColumn([
                'sibling_hint_last_name',
                'sibling_hint_second_last_name',
                'sibling_hint_first_name',
                'sibling_hint_grade',
                'sibling_hint_group',
                'sibling_student_ids',
            ]);
        });
    }
};
