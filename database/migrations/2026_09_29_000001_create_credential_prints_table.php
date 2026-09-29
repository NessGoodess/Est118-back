<?php

use App\Services\Print\CredentialPrintBackfill;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credential_prints', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('academic_year_id')->nullable();
            $table->foreignId('card_design_id')->nullable()->constrained('card_designs')->nullOnDelete();
            $table->string('faces_mode', 16)->default('single');
            $table->string('front_status', 32)->default('pending');
            $table->string('back_status', 32)->default('not_applicable');
            $table->string('strategy', 32)->default('fronts_then_backs');
            $table->uuid('batch_uuid')->index();
            $table->string('reason', 32)->default('initial');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('discarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('discarded_at')->nullable();
            $table->string('discard_reason', 255)->nullable();
            $table->timestamp('front_printed_at')->nullable();
            $table->timestamp('back_printed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['student_id', 'id']);
            $table->index(['front_status', 'back_status']);
        });

        Schema::table('print_jobs', function (Blueprint $table) {
            $table->foreignId('credential_print_id')
                ->nullable()
                ->constrained('credential_prints')
                ->nullOnDelete();
            $table->foreignId('cancelled_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamp('cancelled_at')->nullable();
        });

        app(CredentialPrintBackfill::class)->run();
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('credential_print_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropColumn('cancelled_at');
        });
        Schema::dropIfExists('credential_prints');
    }
};
