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
        Schema::create('nfc_events', function (Blueprint $table) {
             $table->id();
            $table->foreignId('assign_id')->nullable()->constrained('assigned_credential_ids')->onDelete('set null');
            $table->string('status');
            $table->string('reader')->nullable();
            $table->string('uid')->nullable();
            $table->text('message')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('nfc_events');
    }
};
