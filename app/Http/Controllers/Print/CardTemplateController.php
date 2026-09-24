<?php

namespace App\Http\Controllers\Print;

use App\Http\Controllers\Controller;
use App\Models\CardDesign;
use App\Models\User;
use App\Services\Print\CardTemplateService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class CardTemplateController extends Controller
{
    public function __construct(
        private readonly CardTemplateService $templates
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => $this->templates->list($user),
        ]);
    }

    public function show(Request $request, string $template): JsonResponse
    {
        try {
            $design = $this->templates->findDesign($template);
            $this->authorizeView($request, $design);

            return response()->json([
                'success' => true,
                'data' => $this->templates->get($template),
            ]);
        } catch (\Throwable $e) {
            $status = str_contains($e->getMessage(), 'no encontrada') ? 404 : 403;

            return response()->json(['success' => false, 'message' => $e->getMessage()], $status);
        }
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'audience' => ['required', 'in:students,staff,teachers'],
            'grade_level_id' => ['sometimes', 'nullable', 'integer', 'exists:grade_levels,id'],
            'grades' => ['sometimes', 'array'],
            'grades.*' => ['integer'],
            'description' => ['sometimes', 'string', 'max:500'],
            'from_key' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        /** @var User $user */
        $user = $request->user();
        if (empty($data['grade_level_id']) && ! empty($data['grades'][0])) {
            $data['grade_level_id'] = (int) $data['grades'][0];
        }

        try {
            $created = $this->templates->create($user, $data, $data['from_key'] ?? null);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $created], 201);
    }

    public function update(Request $request, string $template): JsonResponse
    {
        $data = $request->validate([
            'meta' => ['required', 'array'],
            'meta.label' => ['required', 'string', 'max:120'],
            'meta.audience' => ['required', 'in:students,staff,teachers'],
            'meta.grade_level_id' => ['sometimes', 'nullable', 'integer', 'exists:grade_levels,id'],
            'meta.grades' => ['sometimes', 'array'],
            'meta.description' => ['sometimes', 'string', 'max:500'],
            'layout' => ['required', 'array'],
        ]);

        try {
            $design = $this->templates->findDesign($template);
            $this->authorizeManage($request, $design);
            $saved = $this->templates->save($template, $data['meta'], $data['layout']);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $status = str_contains($e->getMessage(), 'no encontrada') ? 404 : 422;

            return response()->json(['success' => false, 'message' => $e->getMessage()], $status);
        }

        return response()->json(['success' => true, 'data' => $saved]);
    }

    public function destroy(Request $request, string $template): JsonResponse
    {
        try {
            $design = $this->templates->findDesign($template);
            $this->authorizeManage($request, $design);
            $this->templates->delete($template);
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $status = str_contains($e->getMessage(), 'no encontrada') ? 404 : 422;

            return response()->json(['success' => false, 'message' => $e->getMessage()], $status);
        }

        return response()->json(['success' => true]);
    }

    public function uploadBackground(Request $request, string $template): JsonResponse
    {
        $request->validate([
            'background' => ['required', 'file', 'mimes:png,jpg,jpeg,webp', 'max:8192'],
            'side' => ['sometimes', 'in:front,back'],
        ]);

        try {
            $design = $this->templates->findDesign($template);
            $this->authorizeManage($request, $design);
            $saved = $this->templates->uploadBackground(
                $template,
                $request->file('background'),
                (string) $request->input('side', 'front')
            );
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $saved]);
    }

    public function deleteBackground(Request $request, string $template): JsonResponse
    {
        $request->validate([
            'side' => ['sometimes', 'in:front,back'],
        ]);

        try {
            $design = $this->templates->findDesign($template);
            $this->authorizeManage($request, $design);
            $saved = $this->templates->deleteBackground(
                $template,
                (string) $request->input('side', 'front')
            );
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $saved]);
    }

    public function background(Request $request, string $template): BinaryFileResponse|JsonResponse
    {
        try {
            $design = $this->templates->findDesign($template);
            $this->authorizeView($request, $design);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }

        $side = $request->query('side', 'front');
        if (! in_array($side, ['front', 'back'], true)) {
            $side = 'front';
        }
        $path = $this->templates->backgroundAbsolutePath($template, $side);
        if (! $path) {
            return response()->json(['success' => false, 'message' => 'Sin fondo.'], 404);
        }

        return response()->file($path, [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    public function uploadAsset(Request $request, string $template): JsonResponse
    {
        $request->validate([
            'asset' => ['required', 'file', 'max:8192'],
        ]);

        try {
            $design = $this->templates->findDesign($template);
            $this->authorizeManage($request, $design);
            $saved = $this->templates->uploadAsset($template, $request->file('asset'));
        } catch (HttpException $e) {
            throw $e;
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['success' => true, 'data' => $saved]);
    }

    public function asset(Request $request, string $template, string $filename): BinaryFileResponse|JsonResponse
    {
        try {
            $design = $this->templates->findDesign($template);
            $this->authorizeView($request, $design);
        } catch (\Throwable $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 404);
        }

        $path = $this->templates->assetAbsolutePath($template, $filename);
        if (! $path) {
            return response()->json(['success' => false, 'message' => 'Sin imagen.'], 404);
        }

        return response()->file($path, [
            'Cache-Control' => 'no-store, private',
        ]);
    }

    private function authorizeView(Request $request, CardDesign $design): void
    {
        /** @var User $user */
        $user = $request->user();
        if ($user->hasRole('admin') || $design->is_shared || (int) $design->user_id === (int) $user->id) {
            return;
        }

        abort(403, 'No puedes ver este diseño.');
    }

    private function authorizeManage(Request $request, CardDesign $design): void
    {
        /** @var User $user */
        $user = $request->user();
        if ($this->templates->userCanManage($user, $design)) {
            return;
        }

        abort(403, 'No puedes editar este diseño.');
    }
}
