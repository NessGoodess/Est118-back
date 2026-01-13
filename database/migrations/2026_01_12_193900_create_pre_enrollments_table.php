<?php

use App\Enums\PreEnrollmentStatus;
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
        Schema::create('pre_enrollments', function (Blueprint $table) {
            $table->id();

            // --- Process Control ---
            $table->string('folio')->unique();
            $table->enum('status', PreEnrollmentStatus::cases())->default(PreEnrollmentStatus::PENDING);

            // --- email ---
            $table->string('contact_email')->index();

            // --- Aspirant Data ---
            $table->string('first_name');
            $table->string('last_name');
            $table->string('second_last_name')->nullable();
            $table->string('curp', 18)->index();
            $table->date('birth_date');
            $table->unsignedTinyInteger('age');
            $table->enum('gender', ['M', 'F', 'O']);
            $table->string('phone', 10);
            $table->string('student_email');
            $table->string('place_of_birth');

            // --- Educational Data ---
            $table->string('previous_school');
            $table->decimal('current_average', 4, 2);
            $table->boolean('has_siblings')->default(false);
            $table->text('siblings_details')->nullable();

            // --- Address ---
            $table->string('street_type');
            $table->string('street_name');
            $table->string('house_number');
            $table->string('unit_number')->nullable();
            $table->string('neighborhood_type');
            $table->string('neighborhood_name');
            $table->string('postal_code', 5);
            $table->string('city');
            $table->string('state');

            // --- Guardian ---
            $table->string('guardian_first_name');
            $table->string('guardian_last_name');
            $table->string('guardian_second_last_name')->nullable();
            $table->string('guardian_curp', 18)->index();
            $table->string('guardian_phone', 10);
            $table->string('guardian_relationship');

            // --- Workshop ---
            $table->string('workshop_first_choice');
            $table->string('workshop_second_choice');

            // --- School voucher ---
            $table->boolean('has_school_voucher')->default(false);
            $table->string('school_voucher_folio')->default('0');

            // --- Document paths ---
            $table->string('birth_certificate_path')->nullable();
            $table->string('curp_document_path')->nullable();
            $table->string('address_proof_path')->nullable();
            $table->string('study_certificate_path')->nullable();
            $table->string('photo_path')->nullable();

            // --- Security ---
            $table->ipAddress('ip_address')->nullable();
            $table->string('user_agent')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('pre_enrollments');
    }
};
