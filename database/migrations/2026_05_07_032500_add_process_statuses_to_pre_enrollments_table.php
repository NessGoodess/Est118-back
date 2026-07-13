<?php

use App\Enums\DocumentsStatus;
use App\Enums\PaymentStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pre_enrollments', function (Blueprint $table) {
            $table->enum('documents_status', DocumentsStatus::cases())
                ->default(DocumentsStatus::PENDING)
                ->after('status');

            $table->enum('payment_status', PaymentStatus::cases())
                ->default(PaymentStatus::PENDING)
                ->after('documents_status');
        });
    }

    public function down(): void
    {
        Schema::table('pre_enrollments', function (Blueprint $table) {
            $table->dropColumn(['documents_status', 'payment_status']);
        });
    }
};

