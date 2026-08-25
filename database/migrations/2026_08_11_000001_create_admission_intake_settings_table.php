<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_intake_settings', function (Blueprint $table) {
            $table->id();
            // Scoring / batch placement
            $table->string('score_mode', 32)->default('school_average'); // exam|school_average|combined
            $table->decimal('exam_weight', 3, 2)->default(0.60);
            $table->decimal('average_weight', 3, 2)->default(0.40);
            $table->boolean('balance_load')->default(true);
            $table->boolean('balance_scores')->default(false);
            $table->string('separate_same_school', 16)->default('soft'); // off|soft|hard
            $table->string('separate_siblings', 16)->default('soft');
            $table->string('sibling_detection', 32)->default('guardian_curp'); // linked_only|guardian_curp|lastname_warn

            // Convert gates / exceptions
            $table->boolean('allow_convert_without_complete_docs')->default(false);
            $table->boolean('allow_convert_without_complete_data')->default(false);
            $table->boolean('allow_convert_without_payment')->default(false);
            $table->boolean('require_exam_before_convert')->default(false);
            $table->boolean('require_score_before_placement')->default(true);

            // Post-placement / late intake
            $table->boolean('allow_manual_group_change')->default(true);
            $table->boolean('late_intake_enabled')->default(true);
            $table->boolean('late_requires_manual_group')->default(true);
            $table->boolean('late_suggest_group')->default(true);
            $table->boolean('late_lock_batch_rebalance')->default(true);

            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_intake_settings');
    }
};
