<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_conversion_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('academic_year_id')->constrained('academic_years');
            $table->string('channel', 20)->default('campaign');
            $table->string('status', 20)->default('pending'); // pending|running|completed|failed
            $table->boolean('dry_run')->default(false);
            $table->unsignedInteger('expected_count')->nullable();
            $table->unsignedInteger('requested_count')->default(0);
            $table->unsignedInteger('converted_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->json('policy_snapshot')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->string('request_hash', 64)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('status');
        });

        Schema::create('admission_conversion_batch_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')
                ->constrained('admission_conversion_batches')
                ->cascadeOnDelete();
            $table->unsignedBigInteger('pre_enrollment_id');
            $table->string('status', 20)->default('pending'); // pending|converted|skipped|failed
            $table->unsignedBigInteger('student_id')->nullable();
            $table->unsignedBigInteger('enrollment_id')->nullable();
            $table->string('error_code', 64)->nullable();
            $table->string('message', 500)->nullable();
            $table->json('exception_flags')->nullable();
            $table->timestamps();

            $table->unique(['batch_id', 'pre_enrollment_id'], 'acbi_batch_pre_unique');
            $table->index(['batch_id', 'status'], 'acbi_batch_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_conversion_batch_items');
        Schema::dropIfExists('admission_conversion_batches');
    }
};
