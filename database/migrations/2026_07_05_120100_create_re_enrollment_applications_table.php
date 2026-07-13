<?php

use App\Enums\ReEnrollmentValidationStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('re_enrollment_applications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('re_enrollment_period_id')->constrained('re_enrollment_periods')->cascadeOnDelete();
            $table->foreignId('enrollment_id')->constrained('enrollments')->cascadeOnDelete();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->enum('status', array_column(ReEnrollmentValidationStatus::cases(), 'value'))
                ->default(ReEnrollmentValidationStatus::PENDING->value);

            $table->boolean('passed_cycle')->nullable();
            $table->boolean('documents_complete')->nullable();
            $table->boolean('guardian_updated')->nullable();
            $table->boolean('phone_updated')->nullable();
            $table->boolean('address_updated')->nullable();
            $table->boolean('photo_updated')->nullable();
            $table->boolean('no_debts')->nullable();
            $table->text('comments')->nullable();
            $table->foreignId('target_class_group_id')->nullable()->constrained('class_groups')->nullOnDelete();

            $table->timestamps();

            $table->unique(['re_enrollment_period_id', 'enrollment_id'], 're_enrollment_period_enrollment_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('re_enrollment_applications');
    }
};
