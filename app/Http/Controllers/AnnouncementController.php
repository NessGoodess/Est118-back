<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Jobs\SendAnnouncementToTelegramChannelJob;
use App\Http\Requests\UpsertAnnouncementRequest;
use App\Services\Media\PublicMediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class AnnouncementController extends Controller
{
    /** Directory inside public storage for announcement media */
    private const MEDIA_DIR = PublicMediaStorageService::ANNOUNCEMENTS_DIR;

    public function __construct(private readonly PublicMediaStorageService $media)
    {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public routes (no auth required)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /announcements
     * Returns published announcements ordered by published_at desc.
     * With ?manage=true, returns all for authorised users.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Announcement::query();

        $isManager = $request->boolean('manage') && $request->user('sanctum')?->can('create announcements');

        if (!$isManager) {
            $query->whereNotNull('published_at')
                ->where('published_at', '<=', now());
        }

        $announcements = $query
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->get();

        return response()->json($announcements);
    }

    /**
     * GET /announcements/{announcement}
     */
    public function show(Request $request, Announcement $announcement): JsonResponse
    {
        $isManager = $request->boolean('manage') && $request->user('sanctum')?->can('create announcements');

        if (!$isManager) {
            if (!$announcement->published_at || $announcement->published_at > now()) {
                abort(404, 'Aviso no encontrado o no disponible aún.');
            }
        }

        return response()->json($announcement);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Management routes (auth + permission required)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /announcements
     * Creates a new announcement. Accepts multipart/form-data for file uploads.
     */
    public function store(UpsertAnnouncementRequest $request): JsonResponse
    {
        $validated = $request->validatedPayload();
        $validated = $this->normalizeMediaFields($validated);

        // Handle file upload
        if ($request->hasFile('media_file')) {
            $mediaType = $validated['media_type'];
            $validated['media_src'] = $mediaType === 'image'
                ? $this->storeImage($request->file('media_file'))
                : $this->storeVideo($request->file('media_file'));
        }

        // Slug fallback
        if (empty($validated['slug'])) {
            $validated['slug'] = $this->uniqueSlug($validated['title']);
        }

        $validated['published_at'] = $this->resolvePublishedAt($request, $validated['published_at'] ?? null);

        // Legacy short content: mirror summary for older clients
        $validated['content_type'] = 'text';
        $validated['content_text'] = $validated['summary'] ?? null;
        $validated['content_items'] = null;

        // Created by
        /** @var \App\Models\User|null $user */
        $user = $request->user();
        if ($user !== null) {
            $validated['created_by'] = $user->id;
        }

        $announcement = Announcement::create($validated);

        $this->maybeBroadcastToTelegram($announcement, $request);

        return response()->json($announcement, 201);
    }

    /**
     * PATCH /announcements/{announcement}
     * Updates an existing announcement. New file replaces old one.
     */
    public function update(UpsertAnnouncementRequest $request, Announcement $announcement): JsonResponse
    {
        $validated = $request->validatedPayload();
        $validated = $this->normalizeMediaFields($validated);

        // Handle new file upload
        if ($request->hasFile('media_file')) {
            // Delete previous file if it was a locally stored media
            $this->deleteMediaFile($announcement->media_src);

            $mediaType = $validated['media_type'] ?? $announcement->media_type;
            $validated['media_src'] = $mediaType === 'image'
                ? $this->storeImage($request->file('media_file'))
                : $this->storeVideo($request->file('media_file'));
        }

        // Slug fallback
        if (empty($validated['slug'])) {
            $validated['slug'] = $this->uniqueSlug($validated['title'] ?? $announcement->title, $announcement->id);
        }

        if ($request->has('publish_action')) {
            $validated['published_at'] = $this->resolvePublishedAt(
                $request,
                $validated['published_at'] ?? $announcement->published_at?->toIso8601String()
            );
        }

        if (array_key_exists('summary', $validated)) {
            $validated['content_type'] = 'text';
            $validated['content_text'] = $validated['summary'];
            $validated['content_items'] = null;
        }

        if (array_key_exists('content_blocks', $validated)) {
            $this->pruneContentBlockMedia(
                $announcement->content_blocks,
                $validated['content_blocks']
            );
        }

        $announcement->update($validated);

        $announcement->refresh();
        $this->maybeBroadcastToTelegram($announcement, $request);

        return response()->json($announcement);
    }

    /**
     * DELETE /announcements/{announcement}
     * Deletes announcement and its associated media file.
     */
    public function destroy(Announcement $announcement): JsonResponse
    {
        // Remove physical media files if locally stored
        $this->deleteMediaFile($announcement->media_src);
        $this->media->deleteMany($this->contentBlockMediaSources($announcement->content_blocks));

        $announcement->delete();

        return response()->json(['message' => 'Aviso eliminado correctamente.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolves published_at from publish_action (draft | publish | schedule).
     */
    private function resolvePublishedAt(Request $request, ?string $publishedAt): ?\Illuminate\Support\Carbon
    {
        $action = $request->input('publish_action', 'publish');

        if ($action === 'draft') {
            return null;
        }

        if ($action === 'schedule') {
            if (empty($publishedAt)) {
                abort(422, 'Indica fecha y hora para programar el aviso.');
            }

            return \Illuminate\Support\Carbon::parse($publishedAt);
        }

        // publish now
        return now();
    }

    /**
     * Queue a low-priority Telegram channel post when publishing or scheduling.
     */
    private function maybeBroadcastToTelegram(Announcement $announcement, Request $request): void
    {
        $action = $request->input('publish_action');
        if (! in_array($action, ['publish', 'schedule'], true)) {
            return;
        }

        $chatId = trim((string) config('telegram.announcements.chat_id', ''));
        if ($chatId === '') {
            Log::warning('Telegram broadcast skipped: TELEGRAM_ANNOUNCEMENTS_CHAT_ID empty', [
                'announcement_id' => $announcement->id,
            ]);

            return;
        }

        if (! $announcement->published_at) {
            return;
        }

        $pending = SendAnnouncementToTelegramChannelJob::dispatch($announcement->id);

        if ($announcement->published_at->isFuture()) {
            $pending->delay($announcement->published_at);
        } else {
            $pending->delay(now()->addSeconds(8));
        }
    }

    /**
     * Optimizes and stores an image upload, returning the public URL kept in media_src.
     */
    private function storeImage(\Illuminate\Http\UploadedFile $file): string
    {
        return $this->media->publicPath($this->media->storeImage($file, self::MEDIA_DIR));
    }

    /**
     * Stores a video upload as-is, returning the public URL kept in media_src.
     */
    private function storeVideo(\Illuminate\Http\UploadedFile $file): string
    {
        return $this->media->publicPath($this->media->storeVideo($file, self::MEDIA_DIR));
    }

    /**
     * Deletes a locally stored media file. External links are ignored.
     */
    private function deleteMediaFile(?string $src): void
    {
        $this->media->delete($src);
    }

    /**
     * Every image URL referenced by content blocks (image + gallery blocks).
     *
     * @param  array<int, array<string, mixed>>|null  $blocks
     * @return array<int, string>
     */
    private function contentBlockMediaSources(?array $blocks): array
    {
        return collect($blocks ?? [])
            ->flatMap(function (array $block): array {
                $type = $block['type'] ?? null;

                if ($type === 'image' || $type === 'video') {
                    return [$block['src'] ?? null];
                }

                if ($type === 'gallery') {
                    return collect($block['images'] ?? [])
                        ->map(fn ($image) => is_array($image) ? ($image['src'] ?? null) : null)
                        ->all();
                }

                return [];
            })
            ->filter(fn ($src) => is_string($src) && $src !== '')
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Removes files that were dropped from content blocks during an update.
     *
     * @param  array<int, array<string, mixed>>|null  $previous
     * @param  array<int, array<string, mixed>>|null  $next
     */
    private function pruneContentBlockMedia(?array $previous, ?array $next): void
    {
        $previousKeys = collect($this->contentBlockMediaSources($previous))
            ->map(fn (string $src) => $this->media->storedKey($src))
            ->filter()
            ->all();
        $nextKeys = collect($this->contentBlockMediaSources($next))
            ->map(fn (string $src) => $this->media->storedKey($src))
            ->filter()
            ->all();

        $this->media->deleteMany(array_diff($previousKeys, $nextKeys));
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeMediaFields(array $validated): array
    {
        if (array_key_exists('media_src', $validated)) {
            $validated['media_src'] = $this->media->toStoredSrc($validated['media_src']);
        }

        if (array_key_exists('content_blocks', $validated)) {
            $validated['content_blocks'] = $this->media->normalizeContentBlocks($validated['content_blocks']);
        }

        return $validated;
    }

    /**
     * Generates a unique slug from a base string.
     * Appends a short suffix if the slug is already taken.
     */
    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = Str::slug($base);
        $query = Announcement::where('slug', $slug);
        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        if (!$query->exists()) {
            return $slug;
        }

        return $slug . '-' . Str::random(5);
    }
}
