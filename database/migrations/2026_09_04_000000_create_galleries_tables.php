<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photo albums for the public site, reusable from announcements and events.
     */
    public function up(): void
    {
        Schema::create('galleries', function (Blueprint $table): void {
            $table->id();

            $table->string('slug')->unique();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('category')->index();

            $table->string('cover_src')->nullable();
            $table->boolean('featured')->default(false);
            $table->dateTime('published_at')->nullable()->index();

            $table->foreignId('created_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });

        Schema::create('gallery_items', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('gallery_id')
                ->constrained('galleries')
                ->cascadeOnDelete();

            $table->string('media_src');
            $table->string('alt');
            $table->string('caption')->nullable();
            $table->enum('ratio', ['4/3', '3/4', '1/1', '16/9'])->default('4/3');
            $table->unsignedInteger('sort_order')->default(0);

            $table->timestamps();

            $table->index(['gallery_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gallery_items');
        Schema::dropIfExists('galleries');
    }
};
