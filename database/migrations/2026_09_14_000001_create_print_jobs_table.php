<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->string('printer_id', 64)->default('zc300-recepcion');
            $table->string('template_key', 64)->default('student-card-v1');
            $table->string('side_mode', 16)->default('front');
            $table->string('status', 32)->default('pending');
            $table->unsignedInteger('priority')->default(0);
            $table->json('payload_json')->nullable();
            $table->string('front_path')->nullable();
            $table->string('back_path')->nullable();
            $table->string('claimed_by', 128)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->unsignedInteger('max_attempts')->default(3);
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->timestamps();

            $table->index(['status', 'priority', 'id']);
            $table->index(['printer_id', 'status']);
            $table->index('student_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
    }
};
