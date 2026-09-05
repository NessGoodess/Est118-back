<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\UpsertEventRequest;
use App\Models\Content\Event;
use App\Services\Media\PublicMediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class EventController extends Controller
{
    public function __construct(private readonly PublicMediaStorageService $media)
    {
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Public routes (no auth required)
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * GET /events
     * Published events ordered by start date. Supports ?upcoming, ?from, ?to.
     */
    public function index(Request $request): JsonResponse
    {
        $query = Event::query();

        if (! $this->isManager($request)) {
            $query->published();
        }

        if ($request->boolean('upcoming')) {
            $query->upcoming();
        }

        if ($from = $request->input('from')) {
            $query->where('starts_at', '>=', Carbon::parse($from));
        }

        if ($to = $request->input('to')) {
            $query->where('starts_at', '<=', Carbon::parse($to));
        }

        $events = $query
            ->orderBy('starts_at')
            ->get();

        if ($limit = $request->integer('limit')) {
            $events = $events->take($limit)->values();
        }

        return response()->json($events);
    }

    /**
     * GET /events/{event}
     * Accepts a numeric id or a slug.
     */
    public function show(Request $request, string $event): JsonResponse
    {
        $query = Event::query()->where(is_numeric($event) ? 'id' : 'slug', $event);

        if (! $this->isManager($request)) {
            $query->published();
        }

        $model = $query->first();

        if (! $model) {
            abort(404, 'Evento no encontrado o no disponible aún.');
        }

        return response()->json($model);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Management routes (auth + permission required)
    // ─────────────────────────────────────────────────────────────────────────

    public function store(UpsertEventRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated['slug'] = $this->uniqueSlug($validated['slug'] ?? '' ?: $validated['title']);
        $validated['published_at'] = $this->resolvePublishedAt($request, $validated['published_at'] ?? null);
        $validated['created_by'] = $request->user()?->id;

        $event = Event::create($validated);

        return response()->json($event, 201);
    }

    public function update(UpsertEventRequest $request, Event $event): JsonResponse
    {
        $validated = $request->validated();

        $validated['slug'] = $this->uniqueSlug(
            $validated['slug'] ?? '' ?: ($validated['title'] ?? $event->title),
            $event->id
        );

        if ($request->has('publish_action')) {
            $validated['published_at'] = $this->resolvePublishedAt(
                $request,
                $validated['published_at'] ?? $event->published_at?->toIso8601String()
            );
        }

        if (array_key_exists('content_blocks', $validated)) {
            $this->pruneContentBlockMedia($event->content_blocks, $validated['content_blocks']);
        }

        $event->update($validated);

        return response()->json($event->fresh());
    }

    public function destroy(Event $event): JsonResponse
    {
        // Album photos belong to the gallery, only event-owned media is removed.
        $this->media->deleteMany($this->contentBlockMediaSources($event->content_blocks));
        $this->media->delete($event->cover_src);

        $event->delete();

        return response()->json(['message' => 'Evento eliminado correctamente.']);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function isManager(Request $request): bool
    {
        return $request->boolean('manage')
            && (bool) $request->user('sanctum')?->can('create events');
    }

    /**
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
     * @param  array<int, array<string, mixed>>|null  $previous
     * @param  array<int, array<string, mixed>>|null  $next
     */
    private function pruneContentBlockMedia(?array $previous, ?array $next): void
    {
        $orphans = array_diff(
            $this->contentBlockMediaSources($previous),
            $this->contentBlockMediaSources($next)
        );

        $this->media->deleteMany($orphans);
    }

    private function resolvePublishedAt(Request $request, ?string $publishedAt): ?Carbon
    {
        $action = $request->input('publish_action', 'publish');

        if ($action === 'draft') {
            return null;
        }

        if ($action === 'schedule') {
            if (empty($publishedAt)) {
                abort(422, 'Indica fecha y hora para programar el evento.');
            }

            return Carbon::parse($publishedAt);
        }

        return now();
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = Str::slug($base);
        $query = Event::where('slug', $slug);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists() ? $slug . '-' . Str::random(5) : $slug;
    }
}
