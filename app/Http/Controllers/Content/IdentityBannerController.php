<?php

namespace App\Http\Controllers\Content;

use App\Http\Controllers\Controller;
use App\Http\Requests\Content\UpsertIdentityBannerRequest;
use App\Models\Content\IdentityBanner;
use App\Services\Media\PublicMediaStorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class IdentityBannerController extends Controller
{
    public function __construct(private readonly PublicMediaStorageService $media)
    {
    }

    /**
     * GET /identity-banners
     * Published slides ordered for the home carousel. ?manage=true returns drafts too.
     */
    public function index(Request $request): JsonResponse
    {
        $query = IdentityBanner::query()->ordered();

        if (! $this->isManager($request)) {
            $query->published();
        }

        return response()->json($query->get());
    }

    /**
     * GET /identity-banners/{identityBanner}
     * Accepts a numeric id or a slug.
     */
    public function show(Request $request, string $identityBanner): JsonResponse
    {
        $query = IdentityBanner::query()->where(
            is_numeric($identityBanner) ? 'id' : 'slug',
            $identityBanner
        );

        if (! $this->isManager($request)) {
            $query->published();
        }

        $model = $query->first();

        if (! $model) {
            abort(404, 'Banner no encontrado o no disponible aún.');
        }

        return response()->json($model);
    }

    public function store(UpsertIdentityBannerRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $validated['slug'] = $this->uniqueSlug(
            $validated['slug'] ?? '' ?: ($validated['title'] ?? $validated['alt'] ?? 'banner')
        );
        $validated['published_at'] = $this->resolvePublishedAt($request, $validated['published_at'] ?? null);
        $validated['created_by'] = $request->user()?->id;
        $validated['sort_order'] = $validated['sort_order'] ?? 0;
        $validated['show_copy'] = $request->boolean('show_copy', true);
        $validated['show_cta'] = $request->boolean('show_cta', false);

        if (! $validated['show_copy']) {
            $validated['eyebrow'] = null;
            $validated['title'] = null;
            $validated['description'] = null;
        }

        if (! $validated['show_cta']) {
            $validated['href'] = null;
            $validated['cta'] = null;
        }

        $banner = IdentityBanner::create($validated);

        return response()->json($banner, 201);
    }

    public function update(UpsertIdentityBannerRequest $request, IdentityBanner $identityBanner): JsonResponse
    {
        $validated = $request->validated();

        $validated['slug'] = $this->uniqueSlug(
            $validated['slug'] ?? '' ?: ($validated['title'] ?? $validated['alt'] ?? $identityBanner->slug),
            $identityBanner->id
        );

        if ($request->has('publish_action')) {
            $validated['published_at'] = $this->resolvePublishedAt(
                $request,
                $validated['published_at'] ?? $identityBanner->published_at?->toIso8601String()
            );
        }

        if ($request->has('show_copy')) {
            $validated['show_copy'] = $request->boolean('show_copy');
        }

        if ($request->has('show_cta')) {
            $validated['show_cta'] = $request->boolean('show_cta');
        }

        if (array_key_exists('show_copy', $validated) && ! $validated['show_copy']) {
            $validated['eyebrow'] = null;
            $validated['title'] = null;
            $validated['description'] = null;
        }

        if (array_key_exists('show_cta', $validated) && ! $validated['show_cta']) {
            $validated['href'] = null;
            $validated['cta'] = null;
        }

        $previousSrc = $identityBanner->src;
        $identityBanner->update($validated);

        if (
            array_key_exists('src', $validated)
            && $validated['src'] !== $previousSrc
        ) {
            $this->media->delete($previousSrc);
        }

        return response()->json($identityBanner->fresh());
    }

    public function destroy(IdentityBanner $identityBanner): JsonResponse
    {
        $this->media->delete($identityBanner->src);
        $identityBanner->delete();

        return response()->json(['message' => 'Banner eliminado correctamente.']);
    }

    private function isManager(Request $request): bool
    {
        return $request->boolean('manage')
            && (bool) $request->user('sanctum')?->can('create identity banners');
    }

    private function resolvePublishedAt(Request $request, ?string $publishedAt): ?Carbon
    {
        $action = $request->input('publish_action', 'publish');

        if ($action === 'draft') {
            return null;
        }

        if ($action === 'schedule') {
            if (empty($publishedAt)) {
                abort(422, 'Indica fecha y hora para programar el banner.');
            }

            return Carbon::parse($publishedAt);
        }

        return now();
    }

    private function uniqueSlug(string $base, ?int $ignoreId = null): string
    {
        $slug = Str::slug($base) ?: 'banner';
        $query = IdentityBanner::where('slug', $slug);

        if ($ignoreId) {
            $query->where('id', '!=', $ignoreId);
        }

        return $query->exists() ? $slug.'-'.Str::random(5) : $slug;
    }
}
