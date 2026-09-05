<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * School events: feed both /eventos and the public school calendar.
     */
    public function up(): void
    {
        Schema::create('events', function (Blueprint $table): void {
            $table->id();

            $table->string('slug')->unique();
            $table->string('title');
            $table->string('type')->index();
            $table->text('summary')->nullable();
            $table->json('content_blocks')->nullable();

            $table->dateTime('starts_at')->index();
            $table->dateTime('ends_at')->nullable();
            $table->string('location')->nullable();

            $table->string('cover_src')->nullable();
            $table->boolean('important')->default(false);

            $table->foreignId('gallery_id')
                ->nullable()
                ->constrained('galleries')
                ->nullOnDelete();

            $table->dateTime('published_at')->nullable()->index();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('events');
    }
};
