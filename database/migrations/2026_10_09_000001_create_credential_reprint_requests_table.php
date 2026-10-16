<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Reception asks, school control approves, the comptroller registers the
 * payment and the printer prints. open_student_id keeps one open request
 * per student (null once printed, rejected or cancelled).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'request credential reprints',
        'approve credential reprints',
        'register credential payments',
    ];

    public function up(): void
    {
        Schema::create('credential_reprint_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('student_id')->constrained('students')->cascadeOnDelete();
            $table->foreignId('academic_year_id')->nullable()->constrained('academic_years')->nullOnDelete();
            $table->foreignId('class_group_id')->nullable()->constrained('class_groups')->nullOnDelete();
            $table->string('grade_label', 64)->nullable();
            $table->string('group_label', 64)->nullable();
            $table->string('reason', 32);
            $table->text('notes')->nullable();
            $table->string('requester_relation', 16)->default('student');
            $table->string('requester_name', 160)->nullable();
            $table->string('status', 24)->index();
            $table->boolean('is_chargeable')->default(true);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reject_reason', 255)->nullable();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('paid_at')->nullable();
            $table->string('payment_folio', 64)->nullable();
            $table->decimal('payment_amount', 10, 2)->nullable();
            $table->foreignId('credential_print_id')->nullable()->constrained('credential_prints')->nullOnDelete();
            $table->timestamp('printed_at')->nullable();
            $table->unsignedBigInteger('open_student_id')->nullable()->unique();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::query()->where('name', 'admin')->first()?->givePermissionTo(self::PERMISSIONS);
    }

    public function down(): void
    {
        Schema::dropIfExists('credential_reprint_requests');

        Permission::query()->whereIn('name', self::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
