<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreNewIntakeRequest;
use App\Services\NewIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class NewIntakeController extends Controller
{
    public function __construct(
        private readonly NewIntakeService $intakes,
    ) {}

    public function search(Request $request): JsonResponse
    {
        $this->authorizeIntake($request);

        return response()->json([
            'success' => true,
            'data' => $this->intakes->searchStudents((string) $request->query('q', '')),
        ]);
    }

    public function options(Request $request): JsonResponse
    {
        $this->authorizeIntake($request);

        return response()->json([
            'success' => true,
            'data' => $this->intakes->options(),
        ]);
    }

    public function store(StoreNewIntakeRequest $request): JsonResponse
    {
        try {
            $created = $this->intakes->store($request->validated());
        } catch (RuntimeException $exception) {
            return response()->json([
                'success' => false,
                'message' => $exception->getMessage(),
            ], 422);
        }

        return response()->json([
            'success' => true,
            'message' => 'Alumno de nuevo ingreso registrado.',
            'data' => $created,
        ], 201);
    }

    private function authorizeIntake(Request $request): void
    {
        $user = $request->user();
        if (
            ! $user
            || (
                ! $user->can('edit students')
                && ! $user->can('manage re-enrollment')
                && ! $user->can('create pre-enrollments')
                && ! $user->can('edit admission enrollment')
            )
        ) {
            abort(403, 'No tienes permiso para capturar un nuevo ingreso.');
        }
    }
}
