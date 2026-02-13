<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\EmailVerificationRequest;
use Illuminate\Http\RedirectResponse;

class VerifyEmailController extends Controller
{
    /**
     * Mark the authenticated user's email address as verified.
     */
    public function __invoke(EmailVerificationRequest $request): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return $request->wantsJson()
                ? response()->json(['message' => 'El correo ya ha sido verificado.'])
                : redirect()->intended(config('app.frontend_url').'/login?verified=1');
        }

        if ($request->user()->markEmailAsVerified()) {
            event(new Verified($request->user()));
        }

        return $request->wantsJson() 
            ? response()->json(['message' => 'Correo verificado exitosamente.'])
            : redirect()->intended(config('app.frontend_url').'/login?verified=1');
    }
}
