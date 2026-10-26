<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Reject requests from deactivated user accounts even if they carry a valid,
 * non-expired Sanctum token.
 *
 * HOW INACTIVE-ACCOUNT TOKEN REJECTION WORKS:
 * When a user is deactivated, all their tokens are revoked immediately
 * (tokens()->delete()). This means most requests will already be rejected
 * by auth:sanctum before reaching this middleware. This middleware is a
 * defense-in-depth layer: if a token somehow survives deactivation (e.g.
 * during a race condition window), it catches the case by re-checking
 * is_active on the authenticated user on every protected request.
 */
class EnsureUserIsActive
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && ! $request->user()->is_active) {
            // Revoke all remaining tokens as cleanup
            $request->user()->tokens()->delete();

            return response()->json([
                'message' => 'Akun Anda telah dinonaktifkan. Hubungi administrator.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
