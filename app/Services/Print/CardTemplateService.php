<?php

namespace App\Services\Print;

use App\Models\CardDesign;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use RuntimeException;

class CardTemplateService
{
    public const BASE = 'app/card-templates';

    /**
     * @return array<int, array<string, mixed>>
     */
    public function list(?User $user = null): array
    {
        $query = CardDesign::query()
            ->with(['user:id,name', 'gradeLevel:id,name'])
            ->where('is_active', true)
            ->orderBy('name');

        if ($user) {
            $query->visibleTo($user);
        }

        return $query->get()->map(fn (CardDesign $design) => $this->describe($design))->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public function get(string $key): array
    {
        $design = $this->findDesign($key);
        $dir = $design->storageDir();
        $layout = $this->layoutFromDesign($design);

        $frontBg = $layout['faces']['front']['background_image'] ?? null;
        $backBg = $layout['faces']['back']['background_image'] ?? null;
        $hasFront = is_string($frontBg) && $frontBg !== '' && is_file($dir.'/'.$frontBg);
        $hasBack = is_string($backBg) && $backBg !== '' && is_file($dir.'/'.$backBg);
        $design->loadMissing(['user:id,name', 'gradeLevel:id,name']);
        $uuid = $design->uuid;

        return [
            ...$this->describe($design),
            'key' => $uuid,
            'meta' => $this->metaFromDesign($design),
            'layout' => $layout,
            'has_background' => $hasFront,
            'background_url' => url('/api/card-templates/'.$uuid.'/background?side=front'),
            'backgrounds' => [
                'front' => [
                    'has' => $hasFront,
                    'url' => url('/api/card-templates/'.$uuid.'/background?side=front'),
                ],
                'back' => [
                    'has' => $hasBack,
                    'url' => url('/api/card-templates/'.$uuid.'/background?side=back'),
                ],
            ],
            'available_fields' => $this->availableFields(),
            'available_fonts' => CardFonts::catalog(),
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    public function save(string $key, array $meta, array $layout): array
    {
        $design = $this->findDesign($key);
        $dir = $design->storageDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $layout = $this->normalizeLayout($layout);
        $this->updateDesignFromMeta($design, $meta, $layout);

        return $this->get($design->uuid);
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return array<string, mixed>
     */
    public function create(User $user, array $meta, ?string $fromKey = null): array
    {
        $from = $fromKey ? $this->findDesign($fromKey) : null;
        $uuid = (string) Str::uuid();
        $dir = storage_path(self::BASE.'/'.$uuid);
        mkdir($dir, 0755, true);
        if ($from) {
            $layout = $this->layoutFromDesign($from);
            $fromDir = $from->storageDir();
            foreach (['front', 'back'] as $side) {
                $srcName = $layout['faces'][$side]['background_image'] ?? null;
                $destName = $this->backgroundFilename($side);
                if (is_string($srcName) && $srcName !== '' && is_file($fromDir.'/'.$srcName)) {
                    if (! is_dir($dir)) {
                        mkdir($dir, 0755, true);
                    }
                    copy($fromDir.'/'.$srcName, $dir.'/'.$destName);
                    $layout['faces'][$side]['background_image'] = $destName;
                }
            }
            foreach (glob($fromDir.'/graphic-*') ?: [] as $asset) {
                copy($asset, $dir.'/'.basename($asset));
            }
        } else {
            $layout = $this->defaultLayout();
        }

        $name = trim((string) ($meta['label'] ?? $meta['name'] ?? ''));
        if ($name === '') {
            $name = 'Diseño '.now()->format('Y-m-d H:i');
        }

        $gradeLevelId = ! empty($meta['grade_level_id']) ? (int) $meta['grade_level_id'] : null;

        $design = CardDesign::query()->create([
            'uuid' => $uuid,
            'user_id' => $user->id,
            'grade_level_id' => $gradeLevelId,
            'name' => $name,
            'audience' => in_array(($meta['audience'] ?? 'students'), ['students', 'staff', 'teachers'], true)
                ? $meta['audience']
                : 'students',
            'description' => (string) ($meta['description'] ?? ''),
            'orientation' => $layout['orientation'],
            'faces_mode' => $layout['faces_mode'],
            'is_active' => true,
            'is_shared' => false,
            'is_default' => false,
            'layout_json' => $layout,
        ]);

        return $this->get($design->uuid);
    }

    public function uploadBackground(string $key, UploadedFile $file, string $side = 'front'): array
    {
        $design = $this->findDesign($key);
        $side = $this->assertSide($side);
        $dir = $design->storageDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $layout = $this->layoutFromDesign($design);
        $filename = $this->backgroundFilename($side);
        $file->move($dir, $filename);
        $layout['faces'][$side]['background_image'] = $filename;

        return $this->save($design->uuid, $this->metaFromDesign($design), $layout);
    }

    public function deleteBackground(string $key, string $side = 'front'): array
    {
        $design = $this->findDesign($key);
        $side = $this->assertSide($side);
        $dir = $design->storageDir();
        $layout = $this->layoutFromDesign($design);

        $filename = $layout['faces'][$side]['background_image'] ?? $this->backgroundFilename($side);
        if (is_string($filename) && $filename !== '' && is_file($dir.'/'.$filename)) {
            @unlink($dir.'/'.$filename);
        }
        $layout['faces'][$side]['background_image'] = null;

        return $this->save($design->uuid, $this->metaFromDesign($design), $layout);
    }

    public function backgroundAbsolutePath(string $key, string $side = 'front'): ?string
    {
        $design = $this->findDesign($key);
        $side = $this->assertSide($side);
        $layout = $this->layoutFromDesign($design);
        $filename = $layout['faces'][$side]['background_image'] ?? null;
        if (! is_string($filename) || $filename === '') {
            return null;
        }
        $path = $design->storageDir().'/'.$filename;

        return is_file($path) ? $path : null;
    }

    public function uploadAsset(string $key, UploadedFile $file): array
    {
        $design = $this->findDesign($key);
        $dir = $design->storageDir();
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $ext = strtolower((string) $file->getClientOriginalExtension());
        if (! in_array($ext, ['png', 'jpg', 'jpeg', 'webp', 'svg'], true)) {
            throw new \InvalidArgumentException('Formato no soportado.');
        }

        $filename = 'graphic-'.(string) Str::uuid().'.'.$ext;
        $file->move($dir, $filename);

        return ['filename' => $filename];
    }

    public function assetAbsolutePath(string $key, string $filename): ?string
    {
        $safe = $this->sanitizeAssetName($filename);
        if (! $safe) {
            return null;
        }
        $path = $this->findDesign($key)->storageDir().'/'.$safe;

        return is_file($path) ? $path : null;
    }

    public function directory(string $key): string
    {
        return $this->findDesign($key)->storageDir();
    }

    /**
     * @return array<string, mixed>
     */
    public function layoutFor(string $key): array
    {
        return $this->layoutFromDesign($this->findDesign($key));
    }

    public function orientation(string $key): string
    {
        try {
            $design = $this->findDesign($key);
        } catch (\Throwable) {
            return 'landscape';
        }

        return $design->orientation === 'portrait' ? 'portrait' : 'landscape';
    }

    public function findDesign(string $key): CardDesign
    {
        $key = trim($key);
        $design = CardDesign::query()
            ->where('uuid', $key)
            ->orWhere('legacy_key', $key)
            ->first();

        if (! $design) {
            throw new RuntimeException("Plantilla [{$key}] no encontrada.");
        }

        return $design;
    }

    public function userCanManage(User $user, CardDesign $design): bool
    {
        return $user->hasRole('admin') || (int) $design->user_id === (int) $user->id;
    }

    public function delete(string $key): void
    {
        $design = $this->findDesign($key);
        $uuid = $design->uuid;
        if (! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $uuid)) {
            throw new RuntimeException('No se puede borrar esta carpeta de diseño.');
        }

        $root = str_replace('\\', '/', realpath(storage_path(self::BASE)) ?: storage_path(self::BASE));
        $dir = $design->storageDir();
        if (is_dir($dir)) {
            $realDir = realpath($dir);
            $normalizedDir = $realDir ? str_replace('\\', '/', $realDir) : '';
            if ($normalizedDir !== '' && str_starts_with($normalizedDir, $root) && basename($realDir) === $uuid) {
                File::deleteDirectory($realDir);
            }
        }

        $design->delete();
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public function availableFields(): array
    {
        return [
            ['key' => 'full_name', 'label' => 'Nombre completo'],
            ['key' => 'grade', 'label' => 'Grado'],
            ['key' => 'group', 'label' => 'Grupo'],
            ['key' => 'grade_group', 'label' => 'Grado y grupo'],
            ['key' => 'curp', 'label' => 'CURP'],
            ['key' => 'credential_id', 'label' => 'Folio'],
            ['key' => 'address', 'label' => 'Dirección'],
            ['key' => 'workshop', 'label' => 'Taller'],
            ['key' => 'tutor', 'label' => 'Tutor'],
            ['key' => 'student_phone', 'label' => 'Teléfono alumno'],
            ['key' => 'tutor_phone', 'label' => 'Teléfono tutor'],
            ['key' => 'school', 'label' => 'Escuela'],
            ['key' => 'role', 'label' => 'Rol / cargo (staff)'],
            ['key' => 'department', 'label' => 'Área (staff)'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function describe(CardDesign $design): array
    {
        $gradeId = $design->grade_level_id ? (int) $design->grade_level_id : null;

        return [
            'id' => $design->id,
            'key' => $design->uuid,
            'uuid' => $design->uuid,
            'label' => $design->name,
            'name' => $design->name,
            'audience' => $design->audience,
            'grades' => $gradeId ? [$gradeId] : [],
            'grade_level_id' => $gradeId,
            'grade_name' => $design->gradeLevel?->name,
            'description' => $design->description,
            'orientation' => $design->orientation,
            'faces_mode' => $design->faces_mode,
            'user_id' => $design->user_id,
            'user_name' => $design->user?->name,
            'is_shared' => $design->is_shared,
            'is_default' => $design->is_default,
            'legacy_key' => $design->legacy_key,
            'created_at' => $design->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function metaFromDesign(CardDesign $design): array
    {
        $gradeId = $design->grade_level_id ? (int) $design->grade_level_id : null;

        return [
            'key' => $design->uuid,
            'label' => $design->name,
            'audience' => $design->audience,
            'grades' => $gradeId ? [$gradeId] : [],
            'grade_level_id' => $gradeId,
            'description' => $design->description,
            'user_id' => $design->user_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $layout
     */
    private function updateDesignFromMeta(CardDesign $design, array $meta, array $layout): void
    {
        $name = trim((string) ($meta['label'] ?? $meta['name'] ?? $design->name));
        $gradeId = $meta['grade_level_id'] ?? ($meta['grades'][0] ?? null);
        $gradeId = $gradeId !== null && $gradeId !== '' ? (int) $gradeId : null;

        $design->fill([
            'name' => $name !== '' ? $name : $design->name,
            'audience' => in_array(($meta['audience'] ?? $design->audience), ['students', 'staff', 'teachers'], true)
                ? ($meta['audience'] ?? $design->audience)
                : $design->audience,
            'description' => (string) ($meta['description'] ?? $design->description),
            'grade_level_id' => $gradeId,
            'orientation' => $layout['orientation'] ?? $design->orientation,
            'faces_mode' => $layout['faces_mode'] ?? $design->faces_mode,
            'layout_json' => $layout,
        ]);
        $design->save();
    }

    /**
     * @return array<string, mixed>
     */
    private function layoutFromDesign(CardDesign $design): array
    {
        $stored = $design->layout_json;
        if (is_string($stored) && $stored !== '') {
            $decoded = json_decode($stored, true);
            $stored = is_array($decoded) ? $decoded : [];
        }
        if (is_array($stored) && $stored !== []) {
            $layout = $this->normalizeLayout($stored);
            if (! isset($stored['faces'])) {
                $design->layout_json = $layout;
                $design->save();
            }

            return $layout;
        }

        $path = $design->storageDir().'/layout.json';
        if (is_file($path)) {
            $decoded = json_decode((string) file_get_contents($path), true);
            if (is_array($decoded) && $decoded !== []) {
                $layout = $this->normalizeLayout($decoded);
                $design->layout_json = $layout;
                $design->save();

                return $layout;
            }
        }

        return $this->defaultLayout();
    }

    /**
     * @param  array<string, mixed>  $layout
     * @return array<string, mixed>
     */
    private function normalizeLayout(array $layout): array
    {
        $orientation = ($layout['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape';
        if ($orientation === 'landscape') {
            $width = 1016;
            $height = 648;
        } else {
            $width = 648;
            $height = 1016;
        }

        $facesMode = ($layout['faces_mode'] ?? 'single') === 'double' ? 'double' : 'single';
        $frontSource = is_array($layout['faces']['front'] ?? null)
            ? $layout['faces']['front']
            : $layout;
        $backSource = is_array($layout['faces']['back'] ?? null)
            ? $layout['faces']['back']
            : [];

        $font = CardFonts::normalize((string) ($layout['font'] ?? $frontSource['font'] ?? CardFonts::DEFAULT_FILE));
        [, $pairBold] = CardFonts::pairFromFile($font);
        $fontBold = CardFonts::normalize(
            (string) ($layout['font_bold'] ?? $frontSource['font_bold'] ?? $pairBold),
            $pairBold
        );

        return [
            'width' => $width,
            'height' => $height,
            'dpi' => (int) ($layout['dpi'] ?? 300),
            'orientation' => $orientation,
            'faces_mode' => $facesMode,
            'font' => $font,
            'font_bold' => $fontBold,
            'faces' => [
                'front' => $this->normalizeFace($frontSource, 'front', false, $font),
                'back' => $this->normalizeFace($backSource, 'back', false, $font),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $face
     * @return array<string, mixed>
     */
    private function normalizeFace(array $face, string $side, bool $defaultPhoto, string $defaultFont = CardFonts::DEFAULT_FILE): array
    {
        if (array_key_exists('background_image', $face)) {
            $rawImage = $face['background_image'];
        } else {
            $rawImage = $side === 'front' ? 'front-bg.png' : null;
        }
        $filename = null;
        if (is_string($rawImage) && $rawImage !== '') {
            $filename = $this->backgroundFilename($side);
        }

        $fields = [];
        foreach (($face['fields'] ?? []) as $field) {
            if (! is_array($field) || empty($field['key'])) {
                continue;
            }
            $fields[] = [
                'key' => (string) $field['key'],
                'label' => (string) ($field['label'] ?? ''),
                'x' => (int) ($field['x'] ?? 0),
                'y' => (int) ($field['y'] ?? 0),
                'w' => max(48, (int) ($field['w'] ?? 420)),
                'size' => (float) ($field['size'] ?? 20),
                'bold' => (bool) ($field['bold'] ?? false),
                'color' => (string) ($field['color'] ?? ($face['text'] ?? '#111111')),
                'font' => CardFonts::normalize(
                    (string) (($field['font'] ?? '') !== '' ? $field['font'] : $defaultFont),
                    $defaultFont
                ),
                'align' => in_array(($field['align'] ?? 'left'), ['left', 'center', 'right'], true)
                    ? (string) $field['align']
                    : 'left',
            ];
        }

        $photo = null;
        if (array_key_exists('photo', $face) && $face['photo'] === null) {
            $photo = null;
        } elseif (is_array($face['photo'] ?? null)) {
            $photo = $this->normalizePhoto($face['photo']);
        } elseif ($defaultPhoto) {
            $photo = $this->normalizePhoto([]);
        }

        $qr = null;
        if (array_key_exists('qr', $face) && $face['qr'] === null) {
            $qr = null;
        } elseif (is_array($face['qr'] ?? null)) {
            $qr = $this->normalizeQr($face['qr']);
        }

        $graphics = $this->normalizeGraphics($face['graphics'] ?? []);

        return [
            'background' => (string) ($face['background'] ?? '#FFFFFF'),
            'background_image' => $filename,
            'accent' => (string) ($face['accent'] ?? ''),
            'text' => (string) ($face['text'] ?? '#111111'),
            'photo' => $photo,
            'qr' => $qr,
            'fields' => $fields,
            'graphics' => $graphics,
            'layers' => $this->normalizeLayers($photo, $qr, $fields, $graphics, $face['layers'] ?? []),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $fields
     * @param  array<int, array<string, mixed>>  $graphics
     * @param  mixed  $raw
     * @return array<int, array<string, mixed>>
     */
    private function normalizeLayers($photo, $qr, array $fields, array $graphics, mixed $raw): array
    {
        $present = [];
        foreach ($graphics as $graphic) {
            $present[] = ['kind' => 'graphic', 'id' => (string) $graphic['id']];
        }
        if (is_array($photo)) {
            $present[] = ['kind' => 'photo'];
        }
        if (is_array($qr)) {
            $present[] = ['kind' => 'qr'];
        }
        foreach ($fields as $field) {
            $present[] = ['kind' => 'field', 'key' => (string) $field['key']];
        }

        $presentIds = [];
        foreach ($present as $layer) {
            $presentIds[$this->layerId($layer)] = true;
        }

        $kept = [];
        $seen = [];
        if (is_array($raw)) {
            foreach ($raw as $layer) {
                if (! is_array($layer) || empty($layer['kind'])) {
                    continue;
                }
                $normalized = $this->normalizeLayerRef($layer);
                if (! $normalized) {
                    continue;
                }
                $id = $this->layerId($normalized);
                if (empty($presentIds[$id]) || isset($seen[$id])) {
                    continue;
                }
                $kept[] = $normalized;
                $seen[$id] = true;
            }
        }
        foreach ($present as $layer) {
            $id = $this->layerId($layer);
            if (! isset($seen[$id])) {
                $kept[] = $layer;
                $seen[$id] = true;
            }
        }

        return $kept;
    }

    /**
     * @param  array<string, mixed>  $layer
     * @return array<string, mixed>|null
     */
    private function normalizeLayerRef(array $layer): ?array
    {
        $kind = (string) ($layer['kind'] ?? '');
        if ($kind === 'photo' || $kind === 'qr') {
            return ['kind' => $kind];
        }
        if ($kind === 'graphic' && ! empty($layer['id'])) {
            return ['kind' => 'graphic', 'id' => (string) $layer['id']];
        }
        if ($kind === 'field' && ! empty($layer['key'])) {
            return ['kind' => 'field', 'key' => (string) $layer['key']];
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $layer
     */
    private function layerId(array $layer): string
    {
        $kind = (string) ($layer['kind'] ?? '');
        if ($kind === 'field') {
            return 'field:'.($layer['key'] ?? '');
        }
        if ($kind === 'graphic') {
            return 'graphic:'.($layer['id'] ?? '');
        }

        return $kind;
    }

    /**
     * @param  mixed  $raw
     * @return array<int, array<string, mixed>>
     */
    private function normalizeGraphics(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $item) {
            if (! is_array($item) || empty($item['id'])) {
                continue;
            }
            $kind = $item['kind'] ?? 'rect';
            if (! in_array($kind, ['image', 'rect', 'circle'], true)) {
                $kind = 'rect';
            }
            $src = is_string($item['src'] ?? null) ? $this->sanitizeAssetName((string) $item['src']) : null;
            $out[] = [
                'id' => (string) $item['id'],
                'kind' => $kind,
                'x' => (int) ($item['x'] ?? 0),
                'y' => (int) ($item['y'] ?? 0),
                'w' => max(16, (int) ($item['w'] ?? 120)),
                'h' => max(16, (int) ($item['h'] ?? 120)),
                'src' => $src,
                'fill' => (string) ($item['fill'] ?? '#2563EB'),
                'stroke' => (string) ($item['stroke'] ?? ''),
                'stroke_width' => max(0, min(32, (int) ($item['stroke_width'] ?? 0))),
                'radius' => max(0, (int) ($item['radius'] ?? 0)),
            ];
        }

        return $out;
    }

    private function sanitizeAssetName(string $filename): ?string
    {
        $name = basename($filename);
        if (! preg_match('/^graphic-[A-Za-z0-9._-]+\.(png|jpe?g|webp|svg)$/i', $name)) {
            return null;
        }

        return $name;
    }

    /**
     * @param  array<string, mixed>  $photo
     * @return array<string, mixed>
     */
    private function normalizePhoto(array $photo): array
    {
        return [
            'x' => (int) ($photo['x'] ?? 56),
            'y' => (int) ($photo['y'] ?? 110),
            'w' => (int) ($photo['w'] ?? 300),
            'h' => (int) ($photo['h'] ?? 380),
            'border' => (bool) ($photo['border'] ?? true),
            'border_color' => (string) ($photo['border_color'] ?? '#FFFFFF'),
            'border_width' => max(1, min(32, (int) ($photo['border_width'] ?? 4))),
            'radius' => max(0, (int) ($photo['radius'] ?? 0)),
        ];
    }

    /**
     * @param  array<string, mixed>  $qr
     * @return array<string, mixed>
     */
    private function normalizeQr(array $qr): array
    {
        return [
            'x' => (int) ($qr['x'] ?? 0),
            'y' => (int) ($qr['y'] ?? 0),
            'size' => max(32, (int) ($qr['size'] ?? 120)),
            'color' => (string) ($qr['color'] ?? '#000000'),
            'background' => (string) ($qr['background'] ?? '#FFFFFF'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function defaultLayout(): array
    {
        return $this->normalizeLayout([
            'orientation' => 'landscape',
            'faces_mode' => 'single',
            'faces' => [
                'front' => [
                    'background' => '#FFFFFF',
                    'background_image' => null,
                    'accent' => '',
                    'text' => '#111111',
                    'fields' => [],
                    'photo' => null,
                    'qr' => null,
                ],
                'back' => [
                    'background' => '#FFFFFF',
                    'background_image' => null,
                    'accent' => '',
                    'text' => '#111111',
                    'fields' => [],
                    'photo' => null,
                    'qr' => null,
                ],
            ],
        ]);
    }

    private function backgroundFilename(string $side): string
    {
        return $side === 'back' ? 'back-bg.png' : 'front-bg.png';
    }

    private function assertSide(string $side): string
    {
        return $side === 'back' ? 'back' : 'front';
    }
}
