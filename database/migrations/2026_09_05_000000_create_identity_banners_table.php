<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Home-page identity carousel slides (#identidad).
     */
    public function up(): void
    {
        Schema::create('identity_banners', function (Blueprint $table): void {
            $table->id();

            $table->string('slug')->unique();
            $table->string('src');
            $table->string('alt');

            $table->boolean('show_copy')->default(true);
            $table->string('eyebrow')->nullable();
            $table->string('title')->nullable();
            $table->text('description')->nullable();

            $table->boolean('show_cta')->default(false);
            $table->string('href')->nullable();
            $table->string('cta')->nullable();

            $table->unsignedInteger('sort_order')->default(0)->index();
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
        Schema::dropIfExists('identity_banners');
    }
};
