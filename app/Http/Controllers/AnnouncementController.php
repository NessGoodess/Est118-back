<?php

namespace App\Http\Controllers;

use App\Models\Announcement;
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
            $query->where(function ($q) {
                $q->whereNull('published_at')
                  ->orWhere('published_at', '<=', now());
            });
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
            if ($announcement->published_at && $announcement->published_at > now()) {
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
    public function store(Request $request): JsonResponse
    {
        $validated = $this->validatePayload($request);

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

        // Published at fallback
        if (empty($validated['published_at'])) {
            $validated['published_at'] = now();
        }

        // Created by
        /** @var \App\Models\User|null $user */
        $user = $request->user();
        if ($user !== null) {
            $validated['created_by'] = $user->id;
        }

        $announcement = Announcement::create($validated);

        return response()->json($announcement, 201);
    }

    /**
     * PATCH /announcements/{announcement}
     * Updates an existing announcement. New file replaces old one.
     */
    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $validated = $this->validatePayload($request, $announcement->id);

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

        $announcement->update($validated);

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
     * Shared validation rules for store and update.
     * On update, the slug uniqueness rule ignores the current row.
     */
    private function validatePayload(Request $request, ?int $ignoreId = null): array
    {
        $slugRule = ['nullable', 'string', 'max:255'];
        $slugRule[] = $ignoreId
            ? \Illuminate\Validation\Rule::unique('announcements', 'slug')->ignore($ignoreId)
            : 'unique:announcements,slug';

        return $request->validate([
            'title'                    => ['required', 'string', 'max:255'],
            'header'                   => ['nullable', 'string', 'max:255'],
            'slug'                     => $slugRule,
            'header_alert_enabled'     => ['sometimes', 'boolean'],
            'header_alert_label'       => ['nullable', 'string', 'max:255'],
            'content_type'             => ['required', 'in:text,list'],
            'content_text'             => ['nullable', 'string'],
            'content_items'            => ['nullable', 'array'],
            'content_items.*'          => ['string'],
            'primary_button_label'     => ['nullable', 'string', 'max:255'],
            'primary_button_href'      => ['nullable', 'string', 'max:1024'],
            'primary_button_action'    => ['nullable', 'string', 'max:255'],
            'secondary_button_enabled' => ['sometimes', 'boolean'],
            'secondary_button_label'   => ['nullable', 'string', 'max:255'],
            'secondary_button_href'    => ['nullable', 'string', 'max:1024'],
            'media_type'               => ['required', 'in:image,video,youtube'],
            'media_file'               => ['nullable', 'file', 'max:51200'], // 50 MB
            'media_src'                => ['nullable', 'string', 'max:1024'],
            'media_youtube_id'         => ['nullable', 'string', 'max:255'],
            'media_alt'                => ['required', 'string', 'max:255'],
            'media_ratio'              => ['required', 'in:4/3,3/4,4/4'],
            'published_at'             => ['nullable', 'date'],
            'author'                   => ['nullable', 'string', 'max:255'],
            'type'                     => ['required', 'in:Informativo,Urgente,Recordatorio,Tarea,General'],
            'important'                => ['sometimes', 'boolean'],
            'summary'                  => ['nullable', 'string', 'max:500'],
            'content_blocks'           => ['nullable', 'array'],
        ]);
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
