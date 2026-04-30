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
        Schema::create('announcements', function (Blueprint $table): void {
            $table->id();

            $table->string('slug')->unique();
            $table->string('header');
            $table->string('title');

            $table->boolean('header_alert_enabled')->default(false);
            $table->string('header_alert_label')->nullable();

            $table->enum('content_type', ['text', 'list'])->default('text');
            $table->text('content_text')->nullable();
            $table->json('content_items')->nullable();

            $table->string('primary_button_label')->nullable();
            $table->string('primary_button_href')->nullable();
            $table->string('primary_button_action')->nullable();

            $table->boolean('secondary_button_enabled')->default(false);
            $table->string('secondary_button_label')->nullable();
            $table->string('secondary_button_href')->nullable();

            $table->enum('media_type', ['image', 'video', 'youtube'])->default('image');
            $table->string('media_src')->nullable();
            $table->string('media_youtube_id')->nullable();
            $table->string('media_alt');
            $table->enum('media_ratio', ['4/3', '3/4', '4/4'])->default('4/3');

            $table->dateTime('published_at')->nullable();
            $table->string('author')->nullable();
            $table->enum('type', ['Informativo', 'Urgente', 'Recordatorio', 'Tarea', 'General'])->default('Informativo');
            $table->boolean('important')->default(false);
            $table->text('summary')->nullable();

            $table->json('content_blocks')->nullable();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('announcements');
    }
};

