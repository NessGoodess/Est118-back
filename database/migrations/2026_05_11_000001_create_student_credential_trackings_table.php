<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_credential_trackings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->boolean('credential_printed')->default(false);
            $table->boolean('nfc_ready')->default(false);
            $table->boolean('ready_to_deliver')->default(false);
            $table->boolean('paid')->default(false);
            $table->boolean('delivered')->default(false);
            $table->boolean('lost')->default(false);
            $table->unsignedTinyInteger('replacement_count')->default(0);
            $table->timestamps();

            $table->unique(['student_id', 'academic_year_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_credential_trackings');
    }
};
