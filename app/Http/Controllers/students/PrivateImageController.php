<?php

namespace App\Http\Controllers\students;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class PrivateImageController extends Controller
{
    /**
     * Display the specified resource.
     */
    public function show($path)
    {
        try {
            Log::info('Image request: '. $path);

            $cleanPath = ltrim($path, '/');

            if (str_contains($cleanPath, '..')) {
                Log::error('Traversal path detected: ', [
                    'path' => $path,
                    'user_id' => request()->user()->id ?? 'guest',
                    'ip' => request()->ip(),
                    'method' => request()->method(),
                ]);
                
                return response()->json([
                    'success' => false,
                    'message' => 'unauthorized',
                ], 403);
            }

            if (!Storage::disk('private')->exists($cleanPath)) {

                Log::error('Image not found: '. $cleanPath);
                
                return response()->json([
                    'success' => false,
                    'message' => 'not found',
                ], 404);
            }

            return Storage::disk('private')->response($cleanPath, null, [
                'Cache-Control' => 'private, max-age=86400',
            ]);
            
        } catch (\Throwable $e) {
            Log::error('Error al obtener la imagen: ', [
                'error' => $e->getMessage(),
                'path' => $path,
                'user_id' => request()->user()->id ?? 'guest',
                'ip' => request()->ip(),
                'method' => request()->method(),
            ]);
            
            return response()->json([
                'success' => false,
                'message' => 'internal server error',
            ], 500);
        }
    }
}