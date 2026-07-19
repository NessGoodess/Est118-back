<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfc_read_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('client_event_id')->unique();
            $table->string('event_type', 64);
            $table->string('credential_id', 64)->nullable()->index();
            $table->string('reader_slot_code', 64)->nullable()->index();
            $table->string('reader_pcsc')->nullable();
            $table->json('request_payload');
            $table->string('status', 32)->default('pending')->index();
            $table->json('result_payload')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_read_events');
    }
};
