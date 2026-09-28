<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('place_of_birth', 100)->nullable()->after('credential_id'); // lugar de nacimiento
            $table->string('previous_school', 200)->nullable()->after('place_of_birth'); // escuela anterior
            $table->decimal('current_average', 4, 1)->nullable()->after('previous_school'); // promedio actual
            $table->string('school_voucher_folio', 50)->nullable()->after('current_average'); // folio de becas
        });

        Schema::create('student_siblings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('sibling_student_id')->constrained('students')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['student_id', 'sibling_student_id'], 'student_siblings_pair_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_siblings');

        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn([
                'place_of_birth',
                'previous_school',
                'current_average',
                'school_voucher_folio',
            ]);
        });
    }
};
