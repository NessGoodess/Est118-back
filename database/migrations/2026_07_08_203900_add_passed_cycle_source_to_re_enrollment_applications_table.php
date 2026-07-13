<?php

use App\Enums\PassedCycleSource;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('re_enrollment_applications', function (Blueprint $table) {
            $table->enum('passed_cycle_source', array_column(PassedCycleSource::cases(), 'value'))
                ->default(PassedCycleSource::MANUAL->value)
                ->after('passed_cycle');
        });
    }

    public function down(): void
    {
        Schema::table('re_enrollment_applications', function (Blueprint $table) {
            $table->dropColumn('passed_cycle_source');
        });
    }
};
