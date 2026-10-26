<?php

namespace App\Http\Middleware;

use App\Role;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restrict access to routes based on user role.
 *
 * Usage in routes:
 *   ->middleware('role:admin_gudang')
 *   ->middleware('role:admin_gudang,admin_kasir')
 */
class EnsureRole
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();

        if (! $user || ! in_array($user->role, $roles, strict: true)) {
            return response()->json([
                'message' => 'Anda tidak memiliki izin untuk mengakses sumber daya ini.',
            ], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
