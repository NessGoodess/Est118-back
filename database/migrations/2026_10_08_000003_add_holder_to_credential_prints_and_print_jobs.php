<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Cards can belong to teachers and staff too. student_id stays for student
 * cards (tracking, history, open-card unique index); profile_id + kind
 * identify the holder for every kind.
 */
return new class extends Migration
{
    private const TABLES = ['credential_prints', 'print_jobs'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->foreignId('profile_id')->nullable()->after('student_id')
                    ->constrained('profiles')->nullOnDelete();
                $blueprint->string('kind', 16)->default('students')->after('profile_id');
                $blueprint->index(['kind', 'profile_id']);
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('student_id')->nullable()->change();
            });

            DB::table($table)
                ->whereNull('profile_id')
                ->whereNotNull('student_id')
                ->update([
                    'profile_id' => DB::raw("(select students.profile_id from students where students.id = {$table}.student_id)"),
                ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        Permission::findOrCreate('print credentials', 'web');
        Role::query()->where('name', 'admin')->first()?->givePermissionTo('print credentials');
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            DB::table($table)->whereNull('student_id')->delete();

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropIndex(['kind', 'profile_id']);
                $blueprint->dropConstrainedForeignId('profile_id');
                $blueprint->dropColumn('kind');
            });

            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->unsignedBigInteger('student_id')->nullable(false)->change();
            });
        }

        Permission::query()->where('name', 'print credentials')->delete();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
