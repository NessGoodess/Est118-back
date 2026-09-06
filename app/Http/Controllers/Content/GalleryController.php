<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\UpsertGalleryRequest;
use App\Models\Content\Gallery;
use App\Models\Content\GalleryItem;
use App\Services\Media\PublicMediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class GalleryController extends Controller
{
    public function __construct(private readonly PublicMediaStorageService $media)
    {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public routes (no auth required)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /galleries
     * Published albums with their cover and photo count. ?manage=true returns all.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Gallery::query()->withCount('items');

        if (! $this->isManager($request)) {
            $query->published();
        }

        $category = trim((string) $request->input('category'));
        if ($category !== '') {
            $query->where('category', $category);
        }

        if ($request->boolean('featured')) {
            $query->where('featured', true);
        }

        $galleries = $query
            ->with('items')
            ->orderByDesc('featured')
            ->orderByDesc('published_at')
            ->orderByDesc('created_at')
            ->get()
            ->each(fn (Gallery $gallery) => $gallery->setAttribute('cover_src', $gallery->resolvedCover()));

        if ($limit = $request->integer('limit')) {
            $galleries = $galleries->take($limit)->values();
        }

        // Items were only needed to resolve covers on the listing.
        $galleries->each->unsetRelation('items');

        return response()->json($galleries);
    }

    /**
     * GET /galleries/{gallery}
     * Accepts a numeric id or a slug.
     */
    public function show(Request $request, string $gallery): JsonResponse
    {
        $album = $this->resolveGallery($gallery, $this->isManager($request));

        $album->loadMissing('items');
        $album->setAttribute('cover_src', $album->resolvedCover());

        return response()->json($album);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Management routes (auth + permission required)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * POST /galleries
     */
    public function store(UpsertGalleryRequest $request): JsonResponse
    {
        $validated = $this->normalizeMediaFields($request->validated());
        $items = $validated['items'];
        unset($validated['items']);

        $validated['slug'] = $this->uniqueSlug($validated['slug'] ?? '' ?: $validated['title']);
        $validated['published_at'] = $this->resolvePublishedAt($request, $validated['published_at'] ?? null);
        $validated['created_by'] = $request->user()?->id;

        $gallery = Gallery::create($validated);
        $this->syncItems($gallery, $items);

        return response()->json($this->freshPayload($gallery), 201);
    }

    /**
     * PATCH /galleries/{gallery}
     */
    public function update(UpsertGalleryRequest $request, Gallery $gallery): JsonResponse
    {
        $validated = $this->normalizeMediaFields($request->validated());
        $items = $validated['items'];
        unset($validated['items']);

        $validated['slug'] = $this->uniqueSlug(
            $validated['slug'] ?? '' ?: ($validated['title'] ?? $gallery->title),
            $gallery->id
        );

        if ($request->has('publish_action')) {
            $validated['published_at'] = $this->resolvePublishedAt(
                $request,
                $validated['published_at'] ?? $gallery->published_at?->toIso8601String()
            );
        }

        $gallery->update($validated);
        $this->syncItems($gallery, $items);

        return response()->json($this->freshPayload($gallery));
    }

    /**
     * DELETE /galleries/{gallery}
     * Removes the album with every stored photo.
     */
    public function destroy(Gallery $gallery): JsonResponse
    {
        $this->media->deleteMany(GalleryItem::where('gallery_id', $gallery->id)->pluck('media_src'));
        $this->media->delete($gallery->cover_src);

        $gallery->delete();

        return response()->json(['message' => 'Galería eliminada correctamente.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function isManager(Request $request): bool
    {
        return $request->boolean('manage')
            && (bool) $request->user('sanctum')?->can('create galleries');
    }

    private function resolveGallery(string $idOrSlug, bool $isManager): Gallery
    {
        $query = Gallery::query()->where(
            is_numeric($idOrSlug) ? 'id' : 'slug',
            $idOrSlug
        );

        if (! $isManager) {
            $query->published();
        }

        $gallery = $query->first();

        if (! $gallery) {
            abort(404, 'Galería no encontrada o no disponible aún.');
        }

        return $gallery;
    }

    /**
     * Replaces album photos with the submitted list and drops orphaned files.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function syncItems(Gallery $gallery, array $items): void
    {
        $keep = collect($items)
            ->pluck('media_src')
            ->map(fn ($src) => $this->media->storedKey(is_string($src) ? $src : null))
            ->filter()
            ->unique();

        $removed = GalleryItem::where('gallery_id', $gallery->id)
            ->get()
            ->filter(fn (GalleryItem $item) => ! $keep->contains($this->media->storedKey($item->media_src)))
            ->pluck('media_src');

        GalleryItem::where('gallery_id', $gallery->id)->delete();

        $gallery->items()->createMany(
            collect($items)->values()->map(fn (array $item, int $index): array => [
                'media_src' => $this->media->toStoredSrc($item['media_src']) ?? $item['media_src'],
                'alt' => $item['alt'],
                'caption' => $item['caption'] ?? null,
                'ratio' => $item['ratio'] ?? '4/3',
                'sort_order' => $index,
            ])->all()
        );

        $this->media->deleteMany($removed);
    }

    private function freshPayload(Gallery $gallery): Gallery
    {
        $fresh = $gallery->fresh(['items'])->loadCount('items');
        $fresh->setAttribute('cover_src', $fresh->resolvedCover());

        return $fresh;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function normalizeMediaFields(array $validated): array
    {
        if (array_key_exists('cover_src', $validated)) {
            $validated['cover_src'] = $this->media->toStoredSrc($validated['cover_src']);
        }

        if (isset($validated['items']) && is_array($validated['items'])) {
            $validated['items'] = array_map(function (array $item): array {
                if (isset($item['media_src']) && is_string($item['media_src'])) {
                    $item['media_src'] = $this->media->toStoredSrc($item['media_src']) ?? $item['media_src'];
                }

                return $item;
            }, $validated['items']);
        }

        return $validated;
    }

    /**
     * Resolves published_at from publish_action (draft | publish | schedule).
     */
    private function resolvePublishedAt(Request $request, ?string $publishedAt): ?Carbon
    {
        $action = $request->input('publish_action', 'publish');

        if ($action === 'draft') {
            return null;
        }

        if ($action === 'schedule') {
            if (empty($publishedAt)) {
                abort(422, 'Indica fecha y hora para programar la galería.');
            }

            return Carbon::parse($publishedAt);
        }

        return now();
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = Str::slug($base);
        $query = Gallery::where('slug', $slug);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists() ? $slug . '-' . Str::random(5) : $slug;
    }
}
