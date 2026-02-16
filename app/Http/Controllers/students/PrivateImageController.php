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
            if (!Storage::disk('private')->exists($path)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Imagen no encontrada',
                ], 404);
            }

            return Storage::disk('private')->response($path, null, [
                'Cache-Control' => 'private, max-age=86400',
            ]);
            
        } catch (\Throwable $e) {
            Log::error('Error al obtener la imagen: '. $e->getMessage());
            
            return response()->json([
                'success' => false,
                'message' => 'Error al obtener la imagen',
            ], 500);
        }
    }
}