<?php

namespace App\Http\Controllers\Print;

use App\Enums\CredentialHolderKind;
use App\Http\Controllers\Controller;
use App\Services\Print\CredentialSheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Plain-paper alternative: async sheet jobs, no CredentialPrint history.
 */
class CredentialSheetController extends Controller
{
    public function __construct(
        private readonly CredentialSheetService $sheets,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedSheetRequest($request);
        $kind = CredentialHolderKind::from($data['kind']);

        if ($denied = $this->denyUnlessCan($request, $kind)) {
            return $denied;
        }

        $started = $this->sheets->start(
            $kind,
            $data['ids'],
            $data['omit_fields'] ?? [],
            (int) $request->user()->id
        );

        return response()->json([
            'success' => true,
            'data' => $started,
        ], 202);
    }

    public function show(Request $request, string $uuid): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->sheets->status($uuid, (int) $request->user()->id),
        ]);
    }

    /** Temporary signed URL — no session; expires with the sheet job (~1h). */
    public function asset(string $uuid, int $holderId, string $side): BinaryFileResponse|Response
    {
        if (! in_array($side, ['front', 'back'], true)) {
            abort(404);
        }

        $path = $this->sheets->absolutePath($uuid, $holderId, $side);

        return response()->file($path, [
            'Content-Type' => 'image/png',
            'Cache-Control' => 'private, max-age=300',
        ]);
    }

    /**
     * Legacy sync render (small batches / tests). Prefer {@see store()}.
     */
    public function render(Request $request): JsonResponse
    {
        $data = $this->validatedSheetRequest($request);
        $kind = CredentialHolderKind::from($data['kind']);

        if ($denied = $this->denyUnlessCan($request, $kind)) {
            return $denied;
        }

        return response()->json([
            'success' => true,
            'data' => $this->sheets->render($kind, $data['ids'], $data['omit_fields'] ?? []),
        ]);
    }

    /**
     * @return array{kind: string, ids: array<int, int>, omit_fields?: array<int, string>}
     */
    private function validatedSheetRequest(Request $request): array
    {
        return $request->validate([
            'kind' => ['required', Rule::enum(CredentialHolderKind::class)],
            'ids' => ['required', 'array', 'min:1', 'max:'.CredentialSheetService::MAX_PER_REQUEST],
            'ids.*' => ['integer', 'distinct'],
            'omit_fields' => ['sometimes', 'array'],
            'omit_fields.*' => ['string', 'max:40'],
        ]);
    }

    private function denyUnlessCan(Request $request, CredentialHolderKind $kind): ?JsonResponse
    {
        $permission = $kind === CredentialHolderKind::Students ? 'view students' : 'print credentials';
        if (! $request->user()?->can($permission)) {
            return response()->json(['success' => false, 'message' => 'No tienes permiso para estas credenciales.'], 403);
        }

        return null;
    }
}
