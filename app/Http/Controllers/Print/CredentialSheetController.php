<?php

namespace App\Http\Controllers\Print;

use App\Enums\CredentialHolderKind;
use App\Http\Controllers\Controller;
use App\Services\Print\CredentialSheetService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Plain-paper alternative: returns card images only, no history is kept.
 */
class CredentialSheetController extends Controller
{
    public function __construct(
        private readonly CredentialSheetService $sheets,
    ) {}

    public function render(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', Rule::enum(CredentialHolderKind::class)],
            'ids' => ['required', 'array', 'min:1', 'max:'.CredentialSheetService::MAX_PER_REQUEST],
            'ids.*' => ['integer', 'distinct'],
            'omit_fields' => ['sometimes', 'array'],
            'omit_fields.*' => ['string', 'max:40'],
        ]);
        $kind = CredentialHolderKind::from($data['kind']);

        $permission = $kind === CredentialHolderKind::Students ? 'view students' : 'print credentials';
        if (! $request->user()?->can($permission)) {
            return response()->json(['success' => false, 'message' => 'No tienes permiso para estas credenciales.'], 403);
        }

        return response()->json([
            'success' => true,
            'data' => $this->sheets->render($kind, $data['ids'], $data['omit_fields'] ?? []),
        ]);
    }
}
