<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class PublicVerifyEmailController extends Controller
{
    /**
     * Mark the user's email address as verified (without authentication).
     */
    public function __invoke(Request $request, $id, $hash)
    {
        // Find the user
        $user = User::findOrFail($id);

        // Verify the hash matches
        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return redirect(config('app.frontend_url') . '/verificar-email?status=invalid');
        }

        // Check if the URL signature is valid
        if (! $request->hasValidSignature()) {
            return redirect(config('app.frontend_url') . '/verificar-email?status=expired');
        }

        // Check if already verified
        if ($user->hasVerifiedEmail()) {
            return redirect(config('app.frontend_url') . '/verificar-email?status=already_verified');
        }

        // Mark as verified
        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return redirect(config('app.frontend_url') . '/verificar-email?status=success');
    }
}
