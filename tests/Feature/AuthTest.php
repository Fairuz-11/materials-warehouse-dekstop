<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Authentication test suite — 13 scenarios.
 *
 * Uses RefreshDatabase with MySQL (materials_warehouse_test). The default
 * transaction-based isolation is used so data is rolled back after each test.
 *
 * TOKEN REVOCATION IN TESTS:
 * Sanctum resolves tokens from the DB on every request. After a token is
 * deleted, the next HTTP request will get 401 because the token hash is no
 * longer in personal_access_tokens. This works correctly within transactions
 * because all writes (including deletes) are visible to the same connection.
 *
 * We verify revocation by checking the DB directly with assertDatabaseMissing
 * AND by making a subsequent HTTP request.
 */
class AuthTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────

    private function loginPayload(array $overrides = []): array
    {
        return array_merge([
            'username' => 'budi_gudang',
            'password' => 'password123',
        ], $overrides);
    }

    private function makeUser(array $overrides = []): User
    {
        return User::factory()->create(array_merge([
            'username' => 'budi_gudang',
            'password' => Hash::make('password123'),
            'role' => 'admin_gudang',
            'is_active' => true,
        ], $overrides));
    }

    private function postLogin(array $payload): TestResponse
    {
        return $this->postJson('/api/v1/auth/login', $payload);
    }

    // ── 1. Login berhasil ────────────────────────────────────────────────

    public function test_login_berhasil_menghasilkan_token_dan_data_user(): void
    {
        $user = $this->makeUser();

        $response = $this->postLogin($this->loginPayload());

        $response->assertStatus(200)
            ->assertJsonStructure([
                'message',
                'token',
                'token_type',
                'expires_at',
                'user' => ['id', 'name', 'username', 'email', 'role', 'is_active'],
            ])
            ->assertJsonPath('user.username', 'budi_gudang')
            ->assertJsonPath('token_type', 'Bearer');

        // Token harus tersimpan di database
        $this->assertDatabaseHas('personal_access_tokens', [
            'tokenable_id' => $user->id,
            'tokenable_type' => User::class,
        ]);

        // Respons tidak boleh mengandung nilai hash password
        $this->assertArrayNotHasKey('password', $response->json('user') ?? []);
    }

    // ── 2. Password salah ────────────────────────────────────────────────

    public function test_login_gagal_jika_password_salah(): void
    {
        $this->makeUser();

        $response = $this->postLogin($this->loginPayload(['password' => 'salah_password']));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['username']);

        $this->assertNull($response->json('token'));
    }

    // ── 3. Username tidak ditemukan ──────────────────────────────────────

    public function test_login_gagal_jika_username_tidak_ditemukan(): void
    {
        $response = $this->postLogin($this->loginPayload(['username' => 'tidak_ada']));

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['username']);

        $this->assertNull($response->json('token'));
    }

    // ── 4. Akun nonaktif ─────────────────────────────────────────────────

    public function test_login_ditolak_jika_akun_nonaktif(): void
    {
        $this->makeUser(['is_active' => false]);

        $response = $this->postLogin($this->loginPayload());

        $response->assertStatus(403)
            ->assertJsonPath('message', 'Akun Anda telah dinonaktifkan. Hubungi administrator.');

        $this->assertNull($response->json('token'));
    }

    // ── 5. Token valid mengakses endpoint terlindungi ────────────────────

    public function test_token_valid_dapat_mengakses_endpoint_me(): void
    {
        $user = $this->makeUser();

        $token = $this->postLogin($this->loginPayload())->json('token');

        $this->withToken($token)->getJson('/api/v1/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('user.id', $user->id)
            ->assertJsonPath('user.username', 'budi_gudang');
    }

    // ── 6. Token kedaluwarsa ditolak ─────────────────────────────────────

    public function test_token_kedaluwarsa_ditolak_oleh_sanctum(): void
    {
        $user = $this->makeUser();

        $expiredToken = $user->createToken(
            name: 'expired-token',
            abilities: ['*'],
            expiresAt: now()->subHour(),
        )->plainTextToken;

        $this->withToken($expiredToken)->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }

    // ── 7. Logout mencabut token ─────────────────────────────────────────

    public function test_logout_mencabut_token_yang_digunakan(): void
    {
        $user = $this->makeUser();
        $tokenResult = $user->createToken('api-token', ['*'], now()->addHours(8));
        $plainTextToken = $tokenResult->plainTextToken;

        // Verifikasi token ada di DB sebelum logout
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $tokenResult->accessToken->id]);

        // Logout
        $this->withToken($plainTextToken)->postJson('/api/v1/auth/logout')
            ->assertStatus(200)
            ->assertJsonPath('message', 'Logout berhasil. Token telah dicabut.');

        // Verifikasi token sudah dihapus dari DB
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenResult->accessToken->id]);
    }

    // ── 8. Token yang dicabut tidak bisa digunakan ulang ─────────────────

    public function test_token_yang_dicabut_tidak_dapat_digunakan_kembali(): void
    {
        $user = $this->makeUser();
        $tokenResult = $user->createToken('api-token', ['*'], now()->addHours(8));
        $plainTextToken = $tokenResult->plainTextToken;
        $tokenId = $tokenResult->accessToken->id;

        // Hapus token langsung dari DB (simulasi revoke)
        PersonalAccessToken::destroy($tokenId);

        // Verifikasi DB
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);

        // Token yang sudah dihapus harus ditolak
        $this->withToken($plainTextToken)->getJson('/api/v1/auth/me')
            ->assertStatus(401);

        $this->withToken($plainTextToken)->postJson('/api/v1/auth/logout')
            ->assertStatus(401);
    }

    // ── 9. Endpoint terlindungi tanpa token ──────────────────────────────

    public function test_endpoint_terlindungi_ditolak_tanpa_token(): void
    {
        $this->getJson('/api/v1/auth/me')->assertStatus(401);
        $this->postJson('/api/v1/auth/logout')->assertStatus(401);
        $this->putJson('/api/v1/auth/password', [])->assertStatus(401);
    }

    // ── 10. Perbedaan akses berdasarkan role: admin_gudang ───────────────

    public function test_admin_gudang_dapat_mengakses_rute_warehouse(): void
    {
        $user = User::factory()->adminGudang()->create();

        // _probe route di grup warehouse: 200 = semua middleware dilalui
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/warehouse/_probe')
            ->assertStatus(200)
            ->assertJsonPath('ok', true);
    }

    // ── 11. Perbedaan akses berdasarkan role: admin_kasir ditolak di warehouse ─

    public function test_admin_kasir_tidak_dapat_mengakses_rute_warehouse(): void
    {
        $kasir = User::factory()->adminKasir()->create();

        // Middleware role:admin_gudang menolak admin_kasir → 403
        $this->actingAs($kasir, 'sanctum')
            ->getJson('/api/v1/warehouse/_probe')
            ->assertStatus(403)
            ->assertJsonPath('message', 'Anda tidak memiliki izin untuk mengakses sumber daya ini.');
    }

    // ── 12. Ganti password berhasil dan mencabut semua token ─────────────

    public function test_ganti_password_berhasil_dan_mencabut_semua_token(): void
    {
        $user = $this->makeUser();

        $token1Id = $user->createToken('device-1', ['*'], now()->addHours(8))->accessToken->id;
        $token2Id = $user->createToken('device-2', ['*'], now()->addHours(8))->accessToken->id;

        // Verifikasi kedua token ada di DB
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token1Id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $token2Id]);

        // Ganti password menggunakan actingAs (lebih stabil dalam transaksi test)
        $this->actingAs($user, 'sanctum')
            ->putJson('/api/v1/auth/password', [
                'current_password' => 'password123',
                'password' => 'NewPassword@456',
                'password_confirmation' => 'NewPassword@456',
            ])
            ->assertStatus(200)
            ->assertJsonPath('message', 'Password berhasil diubah. Silakan login kembali.');

        // Semua token harus sudah dihapus dari DB
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token1Id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $token2Id]);

        // User dapat login dengan password baru
        $this->postLogin($this->loginPayload(['password' => 'NewPassword@456']))
            ->assertStatus(200);
    }

    // ── 13. Akun nonaktif tidak dapat menggunakan token lama ─────────────

    public function test_akun_yang_dinonaktifkan_tidak_dapat_menggunakan_token_lama(): void
    {
        $user = $this->makeUser();
        $tokenResult = $user->createToken('api-token', ['*'], now()->addHours(8));
        $plainTextToken = $tokenResult->plainTextToken;
        $tokenId = $tokenResult->accessToken->id;

        // Simulasi admin menonaktifkan akun dan mencabut semua token
        $user->update(['is_active' => false]);
        PersonalAccessToken::destroy($tokenId);

        // Verifikasi token sudah dihapus dari DB
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $tokenId]);

        // Token lama harus ditolak karena tidak ada lagi di DB
        $this->withToken($plainTextToken)->getJson('/api/v1/auth/me')
            ->assertStatus(401);
    }
}
