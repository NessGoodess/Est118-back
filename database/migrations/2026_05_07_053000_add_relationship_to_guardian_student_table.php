<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('guardian_student', function (Blueprint $table) {
            $table->string('relationship', 120)->nullable()->after('guardian_id');
        });
    }

    public function down(): void
    {
        Schema::table('guardian_student', function (Blueprint $table) {
            $table->dropColumn('relationship');
        });
    }
};
