<?php

use App\Enums\NfcReaderAudience;
use App\Enums\NfcReaderDirection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('nfc_reader_slots', function (Blueprint $table) {
            $table->id();
            $table->string('code')->unique();
            $table->string('label');
            $table->enum('audience', array_column(NfcReaderAudience::cases(), 'value'));
            $table->enum('direction', array_column(NfcReaderDirection::cases(), 'value'))->default(NfcReaderDirection::ENTRY->value);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->string('pcsc_name')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('is_armed')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nfc_reader_slots');
    }
};
