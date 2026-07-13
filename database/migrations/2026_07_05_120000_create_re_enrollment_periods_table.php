<?php

use App\Enums\ReEnrollmentPeriodStatus;
use App\Enums\ReEnrollmentProcessStep;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('re_enrollment_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->foreignId('from_academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->foreignId('to_academic_year_id')->constrained('academic_years')->cascadeOnDelete();
            $table->dateTime('start_at')->nullable();
            $table->dateTime('end_at')->nullable();
            $table->enum('status', array_column(ReEnrollmentPeriodStatus::cases(), 'value'))
                ->default(ReEnrollmentPeriodStatus::DRAFT->value);
            $table->enum('current_step', array_column(ReEnrollmentProcessStep::cases(), 'value'))
                ->default(ReEnrollmentProcessStep::CONFIGURATION->value);
            $table->boolean('keep_current_groups')->default(true);
            $table->foreignId('created_by')->constrained('users');
            $table->dateTime('finalized_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('re_enrollment_periods');
    }
};
