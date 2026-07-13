<?php

use App\Enums\ReEnrollmentEventAction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('re_enrollment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('re_enrollment_period_id')->constrained('re_enrollment_periods')->cascadeOnDelete();
            $table->enum('action', array_column(ReEnrollmentEventAction::cases(), 'value'));
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->json('summary')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('re_enrollment_events');
    }
};
