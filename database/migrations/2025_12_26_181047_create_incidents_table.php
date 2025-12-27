<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('incidents', function (Blueprint $table) {
             $table->id();
            $table->foreignId('student_id')->constrained('students');
            $table->foreignId('academic_year_id')->constrained('academic_years');
            $table->foreignId('incident_type_id')->nullable()->constrained('incident_types')->nullOnDelete();
            $table->text('incident_notes')->nullable();
            $table->morphs('recorded_by');
            $table->enum('severity', ['low', 'medium', 'high', 'critical']);
            $table->dateTime('incident_date')->nullable();
            $table->json('evidence')->nullable(); // Fotos, documentos, etc.
            $table->enum('status', ['reported', 'under_review', 'resolved', 'closed']);
            $table->text('resolution')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('incidents');
    }
};
