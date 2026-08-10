<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateAttendanceSettingsRequest;
use App\Models\AttendanceSetting;
use App\Services\AttendanceRulesService;
use Illuminate\Http\JsonResponse;

class AttendanceSettingsController extends Controller
{
    public function __construct(
        private readonly AttendanceRulesService $rules
    ) {}

    public function show(): JsonResponse
    {
        return response()->json([
            'success' => true,
            'data' => $this->rules->toArray(),
        ]);
    }

    public function update(UpdateAttendanceSettingsRequest $request): JsonResponse
    {
        $settings = AttendanceSetting::current();
        $settings->fill($request->validated());
        $settings->updated_by = $request->user()?->id;
        $settings->save();

        $this->rules->refresh();

        return response()->json([
            'success' => true,
            'message' => 'Horario de asistencia actualizado.',
            'data' => $this->rules->toArray(),
        ]);
    }
}
