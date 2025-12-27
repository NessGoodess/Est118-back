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
        Schema::create('student_permissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained();
            $table->foreignId('authorized_by')->constrained('teachers');
            $table->string('reason');
            $table->text('details')->nullable();
            $table->enum('permission_type', ['bathroom', 'nurse', 'early_dismissal', 'other']);
            $table->time('requested_time')->nullable();
            $table->time('authorized_time')->nullable();
            $table->time('return_time')->nullable();
            $table->enum('status', ['requested', 'approved', 'rejected', 'completed']);
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('student_permissions');
    }
};
