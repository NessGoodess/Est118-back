<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('card_designs', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('grade_level_id')->nullable()->constrained('grade_levels')->nullOnDelete();
            $table->string('name', 120);
            $table->string('audience', 32)->default('students');
            $table->string('description', 500)->default('');
            $table->string('orientation', 16)->default('landscape');
            $table->string('faces_mode', 16)->default('single');
            $table->boolean('is_active')->default(true);
            $table->boolean('is_shared')->default(false);
            $table->boolean('is_default')->default(false);
            $table->string('legacy_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['user_id', 'grade_level_id']);
            $table->index('is_active');
        });

        Schema::table('print_jobs', function (Blueprint $table) {
            $table->foreignId('card_design_id')
                ->nullable()
                ->constrained('card_designs')
                ->nullOnDelete();
        });

        $this->importLegacyFolders();
    }

    public function down(): void
    {
        Schema::table('print_jobs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('card_design_id');
        });
        Schema::dropIfExists('card_designs');
    }

    private function importLegacyFolders(): void
    {
        $userId = DB::table('users')->orderBy('id')->value('id');
        if (! $userId) {
            return;
        }

        $root = storage_path('app/card-templates');
        if (! is_dir($root)) {
            return;
        }

        $gradeIds = DB::table('grade_levels')->pluck('id', 'name');

        foreach (File::directories($root) as $dir) {
            $legacyKey = basename($dir);
            if ($legacyKey === 'fonts' || ! is_file($dir.'/layout.json')) {
                continue;
            }
            if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $legacyKey)) {
                continue;
            }
            if (DB::table('card_designs')->where('legacy_key', $legacyKey)->exists()) {
                continue;
            }

            $uuid = (string) Str::uuid();
            $dest = $root.'/'.$uuid;
            File::copyDirectory($dir, $dest);

            $meta = [];
            if (is_file($dir.'/meta.json')) {
                $decoded = json_decode((string) file_get_contents($dir.'/meta.json'), true);
                $meta = is_array($decoded) ? $decoded : [];
            }
            $layout = json_decode((string) file_get_contents($dir.'/layout.json'), true);
            $layout = is_array($layout) ? $layout : [];

            $gradeLevelId = $this->resolveGradeLevelId($legacyKey, $meta, $gradeIds);

            DB::table('card_designs')->insert([
                'uuid' => $uuid,
                'user_id' => $userId,
                'grade_level_id' => $gradeLevelId,
                'name' => (string) ($meta['label'] ?? Str::title(str_replace(['-', '_'], ' ', $legacyKey))),
                'audience' => in_array(($meta['audience'] ?? 'students'), ['students', 'staff', 'teachers'], true)
                    ? $meta['audience']
                    : 'students',
                'description' => (string) ($meta['description'] ?? ''),
                'orientation' => (($layout['orientation'] ?? 'landscape') === 'portrait') ? 'portrait' : 'landscape',
                'faces_mode' => (($layout['faces_mode'] ?? 'single') === 'double') ? 'double' : 'single',
                'is_active' => true,
                'is_shared' => true,
                'is_default' => $legacyKey === 'student-card-v1',
                'legacy_key' => $legacyKey,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  \Illuminate\Support\Collection<string, mixed>  $gradeIds
     */
    private function resolveGradeLevelId(string $legacyKey, array $meta, $gradeIds): ?int
    {
        if (preg_match('/grade-(\d+)/', $legacyKey, $m)) {
            $wanted = $m[1].'°';
            $id = $gradeIds[$wanted] ?? null;
            if ($id) {
                return (int) $id;
            }
        }

        $grades = $meta['grades'] ?? [];
        if (is_array($grades) && $grades !== []) {
            $n = (int) $grades[0];
            $wanted = $n.'°';
            $id = $gradeIds[$wanted] ?? null;
            if ($id) {
                return (int) $id;
            }
            if ($gradeIds->contains($n)) {
                return $n;
            }
        }

        return null;
    }
};
