<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
use App\Jobs\SendAnnouncementToTelegramChannelJob;
use App\Http\Requests\UpsertAnnouncementRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Intervention\Image\ImageManager;
use Intervention\Image\Drivers\Gd\Driver;

class AnnouncementController extends Controller
{
    // ─────────────────────────────────────────────────────────────────────────
    // Constants
    // ─────────────────────────────────────────────────────────────────────────

    /** Directory inside public storage for announcement media */
    private const MEDIA_DIR = 'announcements';

    /** Max width (px) for full-size optimized image */
    private const IMG_MAX_WIDTH = 1280;

    /** Max width (px) for thumbnail */
    private const THUMB_MAX_WIDTH = 640;

    /** JPEG/WebP quality (1–100) */
    private const IMG_QUALITY = 82;

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
        // Remove physical media file if locally stored
        $this->deleteMediaFile($announcement->media_src);

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
     * Optimizes and stores an image upload.
     * Saves the optimized WebP (or JPEG fallback) in public/announcements/.
     * Returns the public URL stored in media_src.
     */
    private function storeImage(\Illuminate\Http\UploadedFile $file): string
    {
        $manager = new ImageManager(new Driver());
        $image   = $manager->read($file->getRealPath());

        // Downscale if wider than max width while preserving ratio
        if ($image->width() > self::IMG_MAX_WIDTH) {
            $image->scaleDown(width: self::IMG_MAX_WIDTH);
        }

        $filename = Str::uuid() . '.webp';
        $path     = self::MEDIA_DIR . '/' . $filename;

        // Encode to WebP and store in public disk
        Storage::disk('public')->put($path, $image->toWebp(self::IMG_QUALITY));

        return Storage::disk('public')->url($path);
    }

    /**
     * Stores a video upload directly (no transcoding — just move to disk).
     * Returns the public URL.
     */
    private function storeVideo(\Illuminate\Http\UploadedFile $file): string
    {
        $ext      = $file->getClientOriginalExtension() ?: 'mp4';
        $filename = Str::uuid() . '.' . $ext;
        $path     = self::MEDIA_DIR . '/' . $filename;

        Storage::disk('public')->put($path, file_get_contents($file->getRealPath()));

        return Storage::disk('public')->url($path);
    }

    /**
     * Deletes a locally stored media file from the public disk.
     * Ignores external URLs (http/https) and null values.
     */
    private function deleteMediaFile(?string $src): void
    {
        if (!$src) return;
        if (str_starts_with($src, 'http')) return; // YouTube or external link

        try {
            // Convert public URL back to relative path
            $publicUrl  = Storage::disk('public')->url('');
            $relativePath = ltrim(str_replace($publicUrl, '', $src), '/');

            if ($relativePath && Storage::disk('public')->exists($relativePath)) {
                Storage::disk('public')->delete($relativePath);
            }
        } catch (\Throwable $e) {
            Log::warning('Could not delete announcement media file', [
                'src'   => $src,
                'error' => $e->getMessage(),
            ]);
        }
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
