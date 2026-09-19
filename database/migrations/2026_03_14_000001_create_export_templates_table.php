<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('export_templates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('context', 64)->index();
            $table->string('path');
            $table->string('original_filename');
            $table->unsignedBigInteger('size')->default(0);
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'context']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('export_templates');
    }
};
