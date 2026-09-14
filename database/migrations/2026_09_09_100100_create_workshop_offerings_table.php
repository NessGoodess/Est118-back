<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_offerings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workshop_id')->constrained('workshops')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->unsignedInteger('capacity')->nullable();
            $table->boolean('is_open_for_intake')->default(true);
            $table->timestamps();

            $table->unique(['workshop_id', 'academic_year_id'], 'workshop_offerings_workshop_year_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_offerings');
    }
};
