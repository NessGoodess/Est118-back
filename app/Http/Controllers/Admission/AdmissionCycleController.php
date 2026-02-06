<?php

namespace App\Http\Controllers\Admission;

use App\Enums\AdmissionCycleStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admission\StoreAdmissionCycleRequest;
use App\Models\Admission\AdmissionCycle;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdmissionCycleController extends Controller
{
    /**
     * Seasons list
     */
    public function index()
    {
        return response()->json(
            AdmissionCycle::orderByDesc('created_at')->get()
        );
    }

    /**
     * Create a new season (ALWAYS starts in draft)
     */
    public function store(StoreAdmissionCycleRequest $request)
    {
        $data = $request->validated();

        $cycle = AdmissionCycle::create([
            ...$data,
            'created_by' => Auth::id(),
            'status' => AdmissionCycleStatus::DRAFT,
        ]);

        return response()->json($cycle, 201);
    }

    /**
     * Activate a season
     *  Only one can exist at a time
     */
    public function activate(AdmissionCycle $cycle)
    {
        if ($cycle->status === AdmissionCycleStatus::ACTIVE) {
            return response()->json([
                'message' => __('admissions.active'),
            ], 422);
        }

        if ($cycle->status === AdmissionCycleStatus::CLOSED) {
            return response()->json([
                'message' => __('admissions.dont_active'),
            ], 422);
        }

        DB::transaction(function () use ($cycle) {

            $activeExists = AdmissionCycle::where('status', AdmissionCycleStatus::ACTIVE)
                ->lockForUpdate()
                ->exists();

            if ($activeExists) {
                throw ValidationException::withMessages([
                    'status' => __('admissions.exist'),
                ]);
            }

            $cycle->update([
                'status' => AdmissionCycleStatus::ACTIVE,
            ]);
        });

        return response()->json([
            'message' => __('admissions.active_success'),
        ]);
    }

    /**
     * Close a season
     */
    public function close(AdmissionCycle $cycle)
    {
        if ($cycle->status === AdmissionCycleStatus::CLOSED) {
            return response()->json([
                'message' => __('admissions.dont_close'),
            ], 422);
        }

        $cycle->update([
            'status' => AdmissionCycleStatus::CLOSED,
        ]);

        return response()->json([
            'message' => __('admissions.closed_success'),
        ]);
    }

    /**
     * Reopen a closed admission cycle
     * Optionally extend the end date if the cycle has expired
     */
    public function reopen(AdmissionCycle $cycle, \App\Http\Requests\Admission\ReopenAdmissionCycleRequest $request)
    {
        if ($cycle->status !== AdmissionCycleStatus::CLOSED) {
            return response()->json([
                'message' => __('admissions.only_closed_can_be_reopened'),
            ], 422);
        }

        DB::transaction(function () use ($cycle, $request) {

            $activeExists = AdmissionCycle::where('status', AdmissionCycleStatus::ACTIVE)
                ->lockForUpdate()
                ->exists();

            if ($activeExists) {
                throw ValidationException::withMessages([
                    'status' => __('admissions.exist'),
                ]);
            }

            $updateData = [
                'status' => AdmissionCycleStatus::ACTIVE,
            ];

            // If a new end date is provided, update it
            if ($request->has('end_at')) {
                $updateData['end_at'] = $request->input('end_at');
            }

            $cycle->update($updateData);
        });

        return response()->json([
            'message' => __('admissions.reopened_success'),
        ]);
    }

    /**
     * Delete a draft admission cycle
     * Only cycles that have never been activated can be deleted
     */
    public function destroy(AdmissionCycle $cycle)
    {
        if ($cycle->status !== AdmissionCycleStatus::DRAFT) {
            return response()->json([
                'message' => 'Solo se pueden eliminar ciclos en borrador que nunca han sido activados.',
            ], 422);
        }

        $cycle->delete();

        return response()->json([
            'message' => 'Ciclo eliminado correctamente.',
        ]);
    }

    /**
     * Public admission status
     * This is the one that consumes the public frontend
     */
    public function status()
    {
        $cycle = AdmissionCycle::where('status', AdmissionCycleStatus::ACTIVE)->first();

        if (! $cycle) {
            return response()->json([
                'enabled' => false,
                'status' => 'not_available',
                'message' => __('admissions.not_available'),
            ]);
        }

        $status = $cycle->publicStatus();

        return response()->json([
            'enabled' => $status === 'active',
            'status' => $status,
            'cycle_id' => $cycle->id,
            'cycle_name' => $cycle->name,
            'start_at' => $cycle->start_at,
            'end_at' => $cycle->end_at,
            'server_time' => now()->toDateTimeString(),
            'message' => match ($status) {
                'not_started' => __('admissions.start_date', [
                    'date' => $cycle->start_at->format('d/m/Y H:i'),
                ]),
                'ended' => __('admissions.end_date'),
                default => null,
            },
        ]);
    }
}
