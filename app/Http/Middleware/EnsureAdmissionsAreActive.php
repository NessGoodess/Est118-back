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

        if (! $cycle || $cycle->publicStatus() !== 'active') {
            return response()->json([
                'message' => __('admissions.not_available'),
            ], 403);
        }

        return $next($request);
    }
}
