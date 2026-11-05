<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * School designs are shared by default; owners can mark them private.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('card_designs')) {
            return;
        }

        DB::table('card_designs')->where('is_shared', false)->update(['is_shared' => true]);

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE card_designs MODIFY is_shared TINYINT(1) NOT NULL DEFAULT 1');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE card_designs ALTER COLUMN is_shared SET DEFAULT true');
        } elseif ($driver === 'sqlite') {
            // SQLite cannot reliably change column defaults in place; create() already sets the value.
            //SQLite no aplica el default, se debe crear la columna con el default
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('card_designs')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::statement('ALTER TABLE card_designs MODIFY is_shared TINYINT(1) NOT NULL DEFAULT 0');
        } elseif ($driver === 'pgsql') {
            DB::statement('ALTER TABLE card_designs ALTER COLUMN is_shared SET DEFAULT false');
        }
    }
};
