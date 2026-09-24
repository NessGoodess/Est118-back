<?php

namespace App\Http\Controllers\Print;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class Zc300AgentDownloadController extends Controller
{
    private const RELATIVE_DIR = 'downloads/zc300-agent';

    public function latest(): JsonResponse
    {
        $disk = Storage::disk('private');
        $metaPath = self::RELATIVE_DIR.'/latest.json';

        if (! $disk->exists($metaPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Instalador del agente ZC300 aún no publicado en el servidor.',
            ], 404);
        }

        /** @var array<string, mixed> $meta */
        $meta = json_decode($disk->get($metaPath), true, 512, JSON_THROW_ON_ERROR);

        return response()->json([
            'success' => true,
            'data' => $meta,
        ]);
    }

    public function file(Request $request): StreamedResponse|JsonResponse
    {
        $disk = Storage::disk('private');
        $metaPath = self::RELATIVE_DIR.'/latest.json';

        if (! $disk->exists($metaPath)) {
            return response()->json([
                'success' => false,
                'message' => 'Instalador del agente ZC300 aún no publicado en el servidor.',
            ], 404);
        }

        /** @var array<string, mixed> $meta */
        $meta = json_decode($disk->get($metaPath), true, 512, JSON_THROW_ON_ERROR);
        $filename = (string) ($meta['filename'] ?? '');
        $relative = self::RELATIVE_DIR.'/'.$filename;

        if ($filename === '' || ! $disk->exists($relative)) {
            return response()->json([
                'success' => false,
                'message' => 'Archivo del instalador no encontrado.',
            ], 404);
        }

        return $disk->download($relative, $filename, [
            'Content-Type' => 'application/octet-stream',
        ]);
    }
}
