<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('profile_user_link_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('linked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 32);
            $table->boolean('email_matched')->default(true);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_user_link_audits');
    }
};
