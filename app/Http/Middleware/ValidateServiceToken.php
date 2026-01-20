<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ValidateServiceToken
{
    /**
     * Handle an incoming request.
     *
     * Validates that the authenticated user has the required token ability.
     * Used to protect endpoints that should only be accessed by service tokens.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \Closure  $next
     * @param  string  $ability  The required token ability (default: 'service:nfc-reader')
     */
    public function handle(Request $request, Closure $next, string $ability = 'service:nfc-reader'): Response
    {
        $user = $request->user('sanctum');

        if (!$user) {
            return response()->json([
                'message' => 'Unauthorized. Missing authentication token.',
            ], 401);
        }

        if (!$user->tokenCan($ability)) {
            return response()->json([
                'message' => 'Forbidden. Token does not have required ability.',
                'required_ability' => $ability,
            ], 403);
        }

        return $next($request);
    }
}
