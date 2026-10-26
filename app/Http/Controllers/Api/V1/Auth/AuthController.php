<?php

namespace App\Http\Controllers\Api\V1\Auth;

use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /**
     * Token lifetime in hours.
     * Sanctum global expiration is kept null; each token receives an explicit
     * expires_at so the lifetime is visible in the database and testable.
     */
    private const TOKEN_TTL_HOURS = 8;

    /**
     * POST /api/v1/auth/login
     *
     * Issues a Sanctum token valid for TOKEN_TTL_HOURS hours.
     * Rejects wrong credentials and inactive accounts before issuing any token.
     * Never logs or returns the hashed password.
     */
    public function login(Request $request): JsonResponse
    {
        $request->validate([
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('username', $request->username)->first();

        // Deliberately use the same error message for "not found" and "wrong
        // password" to avoid username enumeration attacks.
        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'username' => ['Kredensial yang diberikan tidak cocok dengan data kami.'],
            ]);
        }

        if (! $user->is_active) {
            return response()->json([
                'message' => 'Akun Anda telah dinonaktifkan. Hubungi administrator.',
            ], 403);
        }

        $expiresAt = now()->addHours(self::TOKEN_TTL_HOURS);

        $token = $user->createToken(
            name: 'api-token',
            abilities: ['*'],
            expiresAt: $expiresAt,
        );

        return response()->json([
            'message' => 'Login berhasil.',
            'token' => $token->plainTextToken,
            'token_type' => 'Bearer',
            'expires_at' => $expiresAt->toIso8601String(),
            'user' => new UserResource($user),
        ]);
    }

    /**
     * POST /api/v1/auth/logout
     *
     * Revokes the token used for this request. The token is immediately
     * deleted from personal_access_tokens, so reuse is rejected by Sanctum.
     */
    public function logout(Request $request): JsonResponse
    {
        /** @var PersonalAccessToken $token */
        $token = $request->user()->currentAccessToken();
        $token->delete();

        return response()->json([
            'message' => 'Logout berhasil. Token telah dicabut.',
        ]);
    }

    /**
     * GET /api/v1/auth/me
     *
     * Returns authenticated user information — no password or token data.
     */
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => new UserResource($request->user()),
        ]);
    }

    /**
     * PUT /api/v1/auth/password
     *
     * Changes the authenticated user's password and revokes ALL their tokens,
     * forcing re-login on every device. This is intentional: a password change
     * is a security event and should invalidate existing sessions.
     */
    public function updatePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password' => ['required', 'string'],
            'password' => ['required', 'string', 'confirmed', Password::min(8)],
        ]);

        $user = $request->user();

        if (! Hash::check($request->current_password, $user->password)) {
            throw ValidationException::withMessages([
                'current_password' => ['Password saat ini tidak sesuai.'],
            ]);
        }

        $user->update(['password' => $request->password]);

        // Revoke all tokens: password change is a security event.
        $user->tokens()->delete();

        return response()->json([
            'message' => 'Password berhasil diubah. Silakan login kembali.',
        ]);
    }
}
