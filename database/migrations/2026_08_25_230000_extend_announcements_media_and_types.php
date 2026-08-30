<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Incremental upgrades for announcements created before the full schema
 * (Noticia type, media_position, facebook media + facebook_post_url).
 * Idempotent: safe if columns/enums already match the create migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('announcements')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::statement("ALTER TABLE announcements MODIFY COLUMN type ENUM('Informativo','Urgente','Recordatorio','Tarea','General','Noticia') NOT NULL DEFAULT 'Informativo'");
            DB::statement("ALTER TABLE announcements MODIFY COLUMN media_type ENUM('image','video','youtube','facebook') NOT NULL DEFAULT 'image'");
        }

        Schema::table('announcements', function (Blueprint $table): void {
            if (! Schema::hasColumn('announcements', 'media_position')) {
                $table->enum('media_position', ['left', 'right'])
                    ->default('right')
                    ->after('media_ratio');
            }

            if (! Schema::hasColumn('announcements', 'facebook_post_url')) {
                $table->string('facebook_post_url', 1024)
                    ->nullable()
                    ->after('content_blocks');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('announcements')) {
            return;
        }

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'mysql') {
            DB::table('announcements')->where('type', 'Noticia')->update(['type' => 'Informativo']);
            DB::table('announcements')->where('media_type', 'facebook')->update([
                'media_type' => 'image',
                'facebook_post_url' => null,
            ]);
            DB::statement("ALTER TABLE announcements MODIFY COLUMN type ENUM('Informativo','Urgente','Recordatorio','Tarea','General') NOT NULL DEFAULT 'Informativo'");
            DB::statement("ALTER TABLE announcements MODIFY COLUMN media_type ENUM('image','video','youtube') NOT NULL DEFAULT 'image'");
        }

        Schema::table('announcements', function (Blueprint $table): void {
            if (Schema::hasColumn('announcements', 'facebook_post_url')) {
                $table->dropColumn('facebook_post_url');
            }
            if (Schema::hasColumn('announcements', 'media_position')) {
                $table->dropColumn('media_position');
            }
        });
    }
};
