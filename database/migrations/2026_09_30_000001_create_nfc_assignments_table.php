<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfc_assignments', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('academic_year_id')->nullable()->index();
            $table->string('device_id', 128)->nullable();
            $table->string('action', 16);
            $table->string('status', 32)->default('pending')->index();
            $table->string('status_message', 500)->nullable();
            $table->string('failure_code', 32)->nullable();
            $table->string('nfc_uid', 64)->nullable();
            $table->string('expected_credential_id', 64);
            $table->string('read_back', 160)->nullable();
            $table->json('assignment_data')->nullable();
            $table->string('claimed_by', 128)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['student_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_assignments');
    }
};
