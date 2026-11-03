<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    private const PERMISSIONS = [
        'invite teacher accounts',
        'invite staff accounts',
    ];

    public function up(): void
    {
        Schema::create('profile_access_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained('profiles')->cascadeOnDelete();
            $table->string('email');
            $table->string('token_hash', 64);
            $table->json('roles');
            $table->json('permissions')->nullable();
            $table->foreignId('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['profile_id', 'accepted_at']);
            $table->index('token_hash');
            $table->index('expires_at');
        });

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        foreach (self::PERMISSIONS as $permission) {
            Permission::findOrCreate($permission, 'web');
        }
        Role::query()->where('name', 'admin')->first()?->givePermissionTo(self::PERMISSIONS);
    }

    public function down(): void
    {
        Schema::dropIfExists('profile_access_invites');

        Permission::query()->whereIn('name', self::PERMISSIONS)->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
