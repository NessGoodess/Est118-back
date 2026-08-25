<?php

namespace App\Http\Controllers\Admission;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admission\UpdateAdmissionIntakeSettingsRequest;
use App\Models\AdmissionIntakeSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class AdmissionIntakeSettingsController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('auth:sanctum'),
            new Middleware('verified'),
            new Middleware('permission:view admission enrollment')->only(['show']),
            new Middleware('permission:edit admission enrollment')->only(['update']),
        ];
    }

    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => AdmissionIntakeSetting::current()->toApiArray(),
        ]);
    }

    public function update(UpdateAdmissionIntakeSettingsRequest $request): JsonResponse
    {
        $settings = AdmissionIntakeSetting::current();
        $settings->fill($request->validated());
        $settings->updated_by = $request->user()?->id;
        $settings->save();

        return response()->json([
            'success' => true,
            'message' => 'Política de ingreso actualizada.',
            'data' => $settings->fresh()->toApiArray(),
        ]);
    }
}
