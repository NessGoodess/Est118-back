<?php

namespace App\Services\Print;

use App\Enums\CredentialHolderKind;
use App\Jobs\RenderCredentialSheetCardJob;
use App\Models\CardDesign;
use App\Services\People\GuardianCredentialData;
use App\Services\People\PersonPhotoPathService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Plain-paper card images. Renders asynchronously (queue), stores PNGs on the
 * private disk, and expires after {@see self::TTL_SECONDS}. No CredentialPrint history.
 */
class CredentialSheetService
{
    public const MAX_PER_REQUEST = 60;

    /** Keep generated sheets for one hour — enough to preview/print, then purge. */
    public const TTL_SECONDS = 3600;

    public const DISK = 'private';

    public const STORAGE_PREFIX = 'credential-sheets';

    public function __construct(
        private readonly PrintJobService $printJobs,
        private readonly StudentCardRenderService $renderer,
        private readonly PersonPhotoPathService $personPhotos,
    ) {}

    /**
     * @param  array<int, int>  $ids
     * @param  array<int, string>  $omitFields
     * @return array{uuid: string, summary: array{total: int, ready: int, failed: int, rendering: int}}
     */
    public function start(CredentialHolderKind $kind, array $ids, array $omitFields, int $userId): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw ValidationException::withMessages(['ids' => ['Selecciona al menos una credencial.']]);
        }
        if (count($ids) > self::MAX_PER_REQUEST) {
            throw ValidationException::withMessages([
                'ids' => ['Máximo '.self::MAX_PER_REQUEST.' credenciales por lote.'],
            ]);
        }

        $omitFields = array_values(array_unique(array_map('strval', $omitFields)));
        $uuid = (string) Str::uuid();
        $now = now();
        $cards = [];
        // Resolve name/orientation/faces up front so the UI skeleton matches the real card size.
        foreach ($ids as $id) {
            $cards[(string) $id] = $this->pendingMeta($kind, $id);
        }

        $payload = [
            'uuid' => $uuid,
            'user_id' => $userId,
            'kind' => $kind->value,
            'omit_fields' => $omitFields,
            'ids' => $ids,
            'created_at' => $now->toIso8601String(),
            'expires_at' => $now->copy()->addSeconds(self::TTL_SECONDS)->toIso8601String(),
            'cards' => $cards,
        ];

        Cache::put($this->cacheKey($uuid), $payload, self::TTL_SECONDS);
        Storage::disk(self::DISK)->makeDirectory(self::STORAGE_PREFIX.'/'.$uuid);

        foreach ($ids as $id) {
            // No DB rows to wait on (cache + files only); skip afterCommit so tests with
            // RefreshDatabase still see the push immediately.
            RenderCredentialSheetCardJob::dispatch($uuid, $id);
        }

        return [
            'uuid' => $uuid,
            'summary' => $this->summaryFrom($payload),
            'cards' => $this->publicCards($payload),
        ];
    }

    /**
     * @return array{
     *   uuid: string,
     *   status: string,
     *   expires_at: string,
     *   summary: array{total: int, ready: int, failed: int, rendering: int},
     *   cards: array<int, array{id: int, name: string, design_label: string, orientation: string, front: ?string, back: ?string, status: string, error: ?string}>
     * }
     */
    public function status(string $uuid, int $userId): array
    {
        $job = $this->jobOrFail($uuid, $userId);
        $summary = $this->summaryFrom($job);

        return [
            'uuid' => $uuid,
            'status' => $this->overallStatus($summary),
            'expires_at' => $job['expires_at'],
            'summary' => $summary,
            'cards' => $this->publicCards($job),
        ];
    }

    public function absolutePath(string $uuid, int $holderId, string $side): string
    {
        $job = Cache::get($this->cacheKey($uuid));
        if (! is_array($job)) {
            abort(404, 'Lote de hojas expirado o no encontrado.');
        }
        $row = $job['cards'][(string) $holderId] ?? null;
        if (! is_array($row) || ($row['status'] ?? '') !== 'ready') {
            abort(404, 'Imagen aún no lista.');
        }
        $rel = $side === 'back' ? ($row['back_path'] ?? null) : ($row['front_path'] ?? null);
        if (! is_string($rel) || $rel === '' || ! Storage::disk(self::DISK)->exists($rel)) {
            abort(404, 'Archivo no encontrado.');
        }

        return Storage::disk(self::DISK)->path($rel);
    }

    /**
     * Render one holder into private storage and update the job cache.
     * Called from {@see RenderCredentialSheetCardJob}.
     */
    public function renderCard(string $uuid, int $holderId): void
    {
        @ini_set('memory_limit', '512M');

        $job = Cache::get($this->cacheKey($uuid));
        if (! is_array($job)) {
            return;
        }

        $kind = CredentialHolderKind::from((string) $job['kind']);
        $omitFields = $job['omit_fields'] ?? [];

        try {
            [$name, $design, $payload, $studentId, $photoPath] = $kind === CredentialHolderKind::Students
                ? $this->studentCard($holderId)
                : $this->personCard($kind, $holderId);

            foreach ($omitFields as $key) {
                $payload[$key] = '';
            }

            $double = ($design->faces_mode ?? 'single') === 'double';
            $frontRel = $this->storePng($uuid, $holderId, 'front', $design->uuid, $studentId, $payload, $photoPath);
            $backRel = $double
                ? $this->storePng($uuid, $holderId, 'back', $design->uuid, $studentId, $payload, $photoPath)
                : null;

            $this->patchCard($uuid, $holderId, [
                'status' => 'ready',
                'name' => $name,
                'design_label' => (string) $design->name,
                'orientation' => $design->orientation === 'portrait' ? 'portrait' : 'landscape',
                'faces_mode' => $double ? 'double' : 'single',
                'front_path' => $frontRel,
                'back_path' => $backRel,
                'error' => null,
            ]);
        } catch (\Throwable $e) {
            $this->patchCard($uuid, $holderId, [
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Delete sheet folders older than TTL (and drop their cache keys).
     *
     * @return int number of job folders removed
     */
    public function purgeExpired(): int
    {
        $disk = Storage::disk(self::DISK);
        if (! $disk->exists(self::STORAGE_PREFIX)) {
            return 0;
        }

        $removed = 0;
        $cutoff = now()->subSeconds(self::TTL_SECONDS)->getTimestamp();

        foreach ($disk->directories(self::STORAGE_PREFIX) as $dir) {
            $uuid = basename($dir);
            $job = Cache::get($this->cacheKey($uuid));
            $expiredByCache = ! is_array($job);
            $mtime = $disk->lastModified($dir) ?: 0;
            $expiredByAge = $mtime > 0 && $mtime < $cutoff;

            if (! $expiredByCache && ! $expiredByAge) {
                continue;
            }

            $disk->deleteDirectory($dir);
            Cache::forget($this->cacheKey($uuid));
            $removed++;
        }

        return $removed;
    }

    /**
     * Sync render kept for tests / small one-off use. Prefer {@see start()}.
     *
     * @param  array<int, int>  $ids
     * @param  array<int, string>  $omitFields
     * @return array<int, array{id: int, name: string, design_label: string, orientation: string, front: string, back: ?string}>
     */
    public function render(CredentialHolderKind $kind, array $ids, array $omitFields = []): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            throw ValidationException::withMessages(['ids' => ['Selecciona al menos una credencial.']]);
        }

        $rows = [];
        foreach ($ids as $id) {
            [$name, $design, $payload, $studentId, $photoPath] = $kind === CredentialHolderKind::Students
                ? $this->studentCard($id)
                : $this->personCard($kind, $id);

            foreach ($omitFields as $key) {
                $payload[$key] = '';
            }

            $double = ($design->faces_mode ?? 'single') === 'double';
            $rows[] = [
                'id' => $id,
                'name' => $name,
                'design_label' => (string) $design->name,
                'orientation' => $design->orientation === 'portrait' ? 'portrait' : 'landscape',
                'front' => $this->pngDataUrl($design->uuid, $studentId, $payload, 'front', $photoPath),
                'back' => $double ? $this->pngDataUrl($design->uuid, $studentId, $payload, 'back', $photoPath) : null,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, mixed>  $patch
     */
    private function patchCard(string $uuid, int $holderId, array $patch): void
    {
        $key = $this->cacheKey($uuid);
        $lock = Cache::lock('credential-sheet-job-lock:'.$uuid, 10);

        $lock->block(8, function () use ($key, $holderId, $patch) {
            $job = Cache::get($key);
            if (! is_array($job)) {
                return;
            }
            $idKey = (string) $holderId;
            $current = is_array($job['cards'][$idKey] ?? null) ? $job['cards'][$idKey] : [];
            $job['cards'][$idKey] = array_merge($current, $patch, ['id' => $holderId]);
            $expiresAt = \Carbon\Carbon::parse($job['expires_at']);
            $remaining = (int) now()->diffInSeconds($expiresAt, false);
            if ($remaining < 1) {
                return;
            }
            Cache::put($key, $job, $remaining);
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function storePng(
        string $uuid,
        int $holderId,
        string $side,
        string $designKey,
        int $studentId,
        array $payload,
        ?string $photoPath
    ): string {
        $img = $this->renderer->compose($designKey, $studentId, $payload, $side, $photoPath);
        ob_start();
        imagepng($img);
        $binary = (string) ob_get_clean();
        imagedestroy($img);

        $rel = self::STORAGE_PREFIX.'/'.$uuid.'/'.$holderId.'-'.$side.'.png';
        Storage::disk(self::DISK)->put($rel, $binary);

        return $rel;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function pngDataUrl(string $designKey, int $studentId, array $payload, string $side, ?string $photoPath): string
    {
        $img = $this->renderer->compose($designKey, $studentId, $payload, $side, $photoPath);
        ob_start();
        imagepng($img);
        $binary = (string) ob_get_clean();
        imagedestroy($img);

        return 'data:image/png;base64,'.base64_encode($binary);
    }

    /**
     * Lightweight meta for skeletons (no PNG). Falls back to landscape/double if resolve fails.
     *
     * @return array<string, mixed>
     */
    private function pendingMeta(CredentialHolderKind $kind, int $id): array
    {
        $base = [
            'id' => $id,
            'status' => 'pending',
            'name' => '',
            'design_label' => '',
            'orientation' => 'landscape',
            'faces_mode' => 'double',
            'front_path' => null,
            'back_path' => null,
            'error' => null,
        ];

        try {
            if ($kind === CredentialHolderKind::Students) {
                $student = $this->printJobs->studentForPrint($id);
                $design = $this->printJobs->resolveDesignForStudent($student);
                $base['name'] = $student->profile->fullName();
            } else {
                [$name, $design] = array_slice($this->personCard($kind, $id), 0, 2);
                $base['name'] = $name;
            }

            $base['design_label'] = (string) $design->name;
            $base['orientation'] = $design->orientation === 'portrait' ? 'portrait' : 'landscape';
            $base['faces_mode'] = ($design->faces_mode ?? 'single') === 'double' ? 'double' : 'single';
        } catch (\Throwable) {
            // Keep defaults; render job will mark failed if needed.
        }

        return $base;
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<int, array{id: int, name: string, design_label: string, orientation: string, faces_mode: string, front: ?string, back: ?string, status: string, error: ?string}>
     */
    private function publicCards(array $job): array
    {
        $uuid = (string) ($job['uuid'] ?? '');
        $expiresAt = \Carbon\Carbon::parse($job['expires_at']);
        $cards = [];

        foreach ($job['ids'] ?? [] as $id) {
            $row = $job['cards'][(string) $id] ?? null;
            if (! is_array($row)) {
                continue;
            }
            $status = (string) ($row['status'] ?? 'pending');
            $front = null;
            $back = null;
            if ($status === 'ready') {
                $front = isset($row['front_path'])
                    ? $this->signedAssetUrl($uuid, (int) $id, 'front', $expiresAt)
                    : null;
                $back = ! empty($row['back_path'])
                    ? $this->signedAssetUrl($uuid, (int) $id, 'back', $expiresAt)
                    : null;
            }
            $cards[] = [
                'id' => (int) $id,
                'name' => (string) ($row['name'] ?? ''),
                'design_label' => (string) ($row['design_label'] ?? ''),
                'orientation' => ($row['orientation'] ?? 'landscape') === 'portrait' ? 'portrait' : 'landscape',
                'faces_mode' => ($row['faces_mode'] ?? 'double') === 'single' ? 'single' : 'double',
                'front' => $front,
                'back' => $back,
                'status' => $status,
                'error' => $row['error'] ?? null,
            ];
        }

        return $cards;
    }

    /**
     * @return array{0: string, 1: CardDesign, 2: array<string, mixed>, 3: int, 4: ?string}
     */
    private function studentCard(int $studentId): array
    {
        $student = $this->printJobs->studentForPrint($studentId);
        $design = $this->printJobs->resolveDesignForStudent($student);

        return [
            $student->profile->fullName(),
            $design,
            $this->renderer->payloadFromStudent($student),
            (int) $student->id,
            null,
        ];
    }

    /**
     * @return array{0: string, 1: CardDesign, 2: array<string, mixed>, 3: int, 4: ?string}
     */
    private function personCard(CredentialHolderKind $kind, int $id): array
    {
        $model = $kind->personModel();
        $relations = $kind === CredentialHolderKind::Guardians ? GuardianCredentialData::RELATIONS : ['profile.address'];
        $holder = $model::query()->with($relations)->find($id);
        if (! $holder?->profile) {
            throw ValidationException::withMessages(['ids' => ["{$kind->label()} {$id} no encontrado o sin perfil."]]);
        }

        $photoKind = $kind->photoKind();
        $photoPath = $photoKind
            ? ($this->personPhotos->resolvePath($photoKind, $holder->id, 'original')
                ?? $this->personPhotos->resolvePath($photoKind, $holder->id, 'profile'))
            : null;

        return [
            $holder->profile->fullName(),
            $this->printJobs->resolveDesignForPerson($kind),
            $this->renderer->payloadFromPerson($kind, $holder),
            0,
            $photoPath,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function jobOrFail(string $uuid, int $userId): array
    {
        $job = Cache::get($this->cacheKey($uuid));
        if (! is_array($job)) {
            throw ValidationException::withMessages([
                'uuid' => ['Lote de hojas expirado o no encontrado.'],
            ]);
        }
        if ((int) ($job['user_id'] ?? 0) !== $userId) {
            abort(403, 'No tienes acceso a este lote.');
        }

        return $job;
    }

    private function cacheKey(string $uuid): string
    {
        return 'credential-sheet-job:'.$uuid;
    }

    private function signedAssetUrl(string $uuid, int $holderId, string $side, \DateTimeInterface $expiresAt): string
    {
        return URL::temporarySignedRoute(
            'credential-sheets.asset',
            $expiresAt,
            ['uuid' => $uuid, 'holderId' => $holderId, 'side' => $side]
        );
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array{total: int, ready: int, failed: int, rendering: int}
     */
    private function summaryFrom(array $job): array
    {
        $ready = 0;
        $failed = 0;
        $rendering = 0;
        foreach ($job['cards'] ?? [] as $row) {
            $status = is_array($row) ? ($row['status'] ?? 'pending') : 'pending';
            if ($status === 'ready') {
                $ready++;
            } elseif ($status === 'failed') {
                $failed++;
            } else {
                $rendering++;
            }
        }

        return [
            'total' => count($job['ids'] ?? []),
            'ready' => $ready,
            'failed' => $failed,
            'rendering' => $rendering,
        ];
    }

    /**
     * @param  array{total: int, ready: int, failed: int, rendering: int}  $summary
     */
    private function overallStatus(array $summary): string
    {
        if ($summary['rendering'] > 0) {
            return 'processing';
        }
        if ($summary['failed'] > 0 && $summary['ready'] === 0) {
            return 'failed';
        }
        if ($summary['failed'] > 0) {
            return 'partial';
        }

        return 'ready';
    }
}
