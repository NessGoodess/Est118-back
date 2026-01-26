<?php

namespace App\Http\Middleware;

use App\Models\Admission\AdmissionCycle;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAdmissionsAreActive
{
    public function handle(Request $request, Closure $next): Response
    {
        $cycle = AdmissionCycle::where('status', 'active')->first();

        if (! $cycle) {
            return response()->json([
                'message' => __('admissions.not_available'),
            ], 403);
        }

        $now = now();

        if ($now->lt($cycle->start_date)) {
            return response()->json([
                'message' => __('admissions.not_started'),
            ], 403);
        }

        if ($now->gt($cycle->end_date)) {
            return response()->json([
                'message' => __('admissions.ended'),
            ], 403);
        }

        return $next($request);
    }
}
