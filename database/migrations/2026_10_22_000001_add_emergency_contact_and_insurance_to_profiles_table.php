<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Insurance stays on profiles (1:1). Emergency contacts are normalized
     * so staff/teachers can store more than one.
     */
    public function up(): void
    {
        Schema::table('profiles', function (Blueprint $table) {
            $table->string('insurance_type')->nullable()->after('phone_second_number');
            $table->string('insurance_number')->nullable()->after('insurance_type');
        });

        Schema::create('emergency_contacts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('name');
            $table->string('phone');
            $table->string('relationship')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['profile_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('emergency_contacts');

        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['insurance_type', 'insurance_number']);
        });
    }
};
