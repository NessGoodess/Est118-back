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
        Schema::create('nfc_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->nullable()->constrained("assigned_credential_ids")->onDelete('set null');
            $table->string('device_id')->nullable();
            $table->string('status');
            $table->string('status_message')->nullable();
            $table->string('nfc_uid')->nullable();
            $table->json('assignment_data')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nfc_assignments');
    }
};
