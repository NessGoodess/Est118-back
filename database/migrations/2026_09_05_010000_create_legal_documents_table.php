<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * School legal PDFs: public privacy/rules and an internal student-photos notice.
     */
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table): void {
            $table->id();

            $table->string('type', 32)->unique();
            $table->string('title');
            $table->string('src')->nullable();
            $table->string('original_name')->nullable();

            $table->dateTime('published_at')->nullable()->index();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
