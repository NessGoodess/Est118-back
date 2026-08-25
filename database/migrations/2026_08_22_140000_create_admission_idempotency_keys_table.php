<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('scope', 64);
            $table->string('owner_key', 64);
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->unique(['scope', 'owner_key', 'idempotency_key'], 'admission_idempotency_scope_owner_key_unique');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_idempotency_keys');
    }
};
