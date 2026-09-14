<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpsertWorkshopOfferingsRequest;
use App\Models\AcademicYear;
use App\Models\Workshop;
use App\Models\WorkshopEnrollment;
use App\Models\WorkshopOffering;
use App\Enums\WorkshopEnrollmentStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controllers\HasMiddleware;
use Illuminate\Routing\Controllers\Middleware;

class WorkshopOfferingController extends Controller implements HasMiddleware
{
    public static function middleware(): array
    {
        return [
            new Middleware('auth:sanctum'),
            new Middleware('verified'),
        ];
    }

    public function index(Request $request): JsonResponse
    {
        $yearId = $request->filled('academic_year_id')
            ? (int) $request->integer('academic_year_id')
            : (int) AcademicYear::query()->where('is_active', true)->value('id');

        if ($yearId <= 0) {
            return response()->json([
                'success' => false,
                'message' => 'Indica un ciclo escolar.',
            ], 422);
        }

        $this->ensureOfferings($yearId);

        $offerings = WorkshopOffering::query()
            ->with('workshop:id,name,code')
            ->where('academic_year_id', $yearId)
            ->get();

        $occupied = WorkshopEnrollment::query()
            ->where('academic_year_id', $yearId)
            ->where('status', WorkshopEnrollmentStatus::Assigned->value)
            ->selectRaw('workshop_id, COUNT(*) as occupied')
            ->groupBy('workshop_id')
            ->pluck('occupied', 'workshop_id');

        $data = $offerings->map(function (WorkshopOffering $offering) use ($occupied) {
            $taken = (int) ($occupied[$offering->workshop_id] ?? 0);
            $capacity = $offering->capacity;

            return [
                'id' => $offering->id,
                'workshop_id' => $offering->workshop_id,
                'academic_year_id' => $offering->academic_year_id,
                'workshop_name' => $offering->workshop?->name,
                'workshop_code' => $offering->workshop?->code,
                'capacity' => $capacity,
                'is_open_for_intake' => $offering->is_open_for_intake,
                'occupied' => $taken,
                'available' => $capacity === null ? null : max(0, (int) $capacity - $taken),
            ];
        })->values();

        return response()->json([
            'success' => true,
            'academic_year_id' => $yearId,
            'data' => $data,
        ]);
    }

    public function upsert(UpsertWorkshopOfferingsRequest $request): JsonResponse
    {
        $user = $request->user();
        if (
            ! $user
            || (
                ! $user->can('edit student workshops')
                && ! $user->can('edit admission enrollment')
            )
        ) {
            return response()->json([
                'success' => false,
                'message' => 'No tienes permiso para editar cupos de taller.',
            ], 403);
        }

        $yearId = (int) $request->integer('academic_year_id');
        AcademicYear::findOrFail($yearId);

        foreach ($request->input('offerings', []) as $row) {
            WorkshopOffering::query()->updateOrCreate(
                [
                    'workshop_id' => (int) $row['workshop_id'],
                    'academic_year_id' => $yearId,
                ],
                [
                    'capacity' => array_key_exists('capacity', $row) ? $row['capacity'] : null,
                    'is_open_for_intake' => array_key_exists('is_open_for_intake', $row)
                        ? (bool) $row['is_open_for_intake']
                        : true,
                ]
            );
        }

        $request->merge(['academic_year_id' => $yearId]);

        return $this->index($request);
    }

    private function ensureOfferings(int $yearId): void
    {
        foreach (Workshop::query()->where('is_active', true)->get() as $workshop) {
            WorkshopOffering::query()->firstOrCreate(
                [
                    'workshop_id' => $workshop->id,
                    'academic_year_id' => $yearId,
                ],
                [
                    'capacity' => null,
                    'is_open_for_intake' => true,
                ]
            );
        }
    }
}
