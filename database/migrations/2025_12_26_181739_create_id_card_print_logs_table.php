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
        Schema::create('id_card_print_logs', function (Blueprint $table) {
             $table->id();
            $table->foreignId('id_card_id')->constrained('id_cards')->cascadeOnDelete();
            $table->nullableMorphs('generated_by');
            $table->string('rendered_card_path')->nullable();
            $table->dateTime('printed_at');
            $table->string('printer_name')->nullable(); // zebra zc300
            $table->enum('reason', ['initial', 'reprint', 'replacement'])->default('initial');
            $table->text('notes')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('id_card_print_logs');
    }
};
