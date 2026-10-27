<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\StockTransactionItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Stock Transaction API — 14 scenarios.
 *
 * Routes under test:
 *   GET  /api/v1/stock-transactions
 *   POST /api/v1/stock-in
 *   POST /api/v1/stock-out
 *   GET  /api/v1/stock-transactions/{id}
 *   PUT  /api/v1/stock-transactions/{id}
 *   POST /api/v1/stock-transactions/{id}/confirm
 *   POST /api/v1/stock-transactions/{id}/cancel
 */
class StockTransactionTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ──────────────────────────────────────────────────────────

    private function gudang(): User
    {
        return User::factory()->adminGudang()->create();
    }

    private function kasir(): User
    {
        return User::factory()->adminKasir()->create();
    }

    /** Create an active product with a known stock level (set via raw DB). */
    private function productWithStock(float $stock = 0, array $overrides = []): Product
    {
        $category = Category::factory()->create();
        $product = Product::factory()->forCategory($category)->create($overrides);

        if ($stock > 0) {
            $product->getConnection()
                ->table('products')
                ->where('id', $product->id)
                ->update(['current_stock' => $stock]);
        }

        return $product->fresh();
    }

    private function stockInPayload(int $productId, float $qty = 10, ?float $price = 50000): array
    {
        return [
            'transaction_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $productId,
                'quantity' => $qty,
                'purchase_price' => $price,
            ]],
        ];
    }

    private function stockOutPayload(int $productId, float $qty = 5): array
    {
        return [
            'transaction_date' => now()->toDateString(),
            'items' => [[
                'product_id' => $productId,
                'quantity' => $qty,
            ]],
        ];
    }

    // ── 1. Barang masuk menambah stok setelah konfirmasi ─────────────────

    public function test_stock_in_menambah_stok_setelah_konfirmasi(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(0);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stock-in', $this->stockInPayload($product->id, 10));
        $response->assertStatus(201);

        $txId = $response->json('transaction.id');

        // Stok belum berubah (masih draft)
        $this->assertEquals(0, (float) $product->fresh()->current_stock);

        // Konfirmasi
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-transactions/{$txId}/confirm")
            ->assertStatus(200);

        // Stok bertambah 10
        $this->assertEquals(10, (float) $product->fresh()->current_stock);
    }

    // ── 2. Barang keluar mengurangi stok setelah konfirmasi ──────────────

    public function test_stock_out_mengurangi_stok_setelah_konfirmasi(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(20);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stock-out', $this->stockOutPayload($product->id, 8));
        $response->assertStatus(201);

        $txId = $response->json('transaction.id');

        // Stok belum berubah
        $this->assertEquals(20, (float) $product->fresh()->current_stock);

        // Konfirmasi
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-transactions/{$txId}/confirm")
            ->assertStatus(200);

        // Stok berkurang 8
        $this->assertEquals(12, (float) $product->fresh()->current_stock);
    }

    // ── 3. Draft tidak mengubah stok ─────────────────────────────────────

    public function test_draft_tidak_mengubah_stok(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(5);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stock-in', $this->stockInPayload($product->id, 100))
            ->assertStatus(201);

        // Tidak ada konfirmasi — stok tetap
        $this->assertEquals(5, (float) $product->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    // ── 4. Transaksi batal tidak mengubah stok ───────────────────────────

    public function test_transaksi_batal_tidak_mengubah_stok(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(5);

        $txId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stock-in', $this->stockInPayload($product->id, 10))
            ->json('transaction.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-transactions/{$txId}/cancel")
            ->assertStatus(200)
            ->assertJsonPath('transaction.status', 'cancelled');

        // Stok tidak berubah
        $this->assertEquals(5, (float) $product->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    // ── 5. Stok tidak mencukupi ditolak (stock_out) ──────────────────────

    public function test_stok_tidak_mencukupi_ditolak_saat_buat_stock_out(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(3);

        // Minta keluar 10, stok hanya 3
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stock-out', $this->stockOutPayload($product->id, 10))
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($v) => str_contains($v, 'tidak mencukupi'));

        $this->assertDatabaseCount('stock_transactions', 0);
    }

    // ── 6. Stok tidak mencukupi juga ditolak saat konfirmasi ─────────────

    public function test_stok_tidak_mencukupi_ditolak_saat_konfirmasi(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(10);

        // Buat draft stock_out dengan qty 8 (valid)
        $txId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stock-out', $this->stockOutPayload($product->id, 8))
            ->json('transaction.id');

        // Simulasi: stock dikurangi oleh transaksi lain sebelum konfirmasi
        $product->getConnection()
            ->table('products')
            ->where('id', $product->id)
            ->update(['current_stock' => 2]);

        // Konfirmasi harus gagal karena stok sudah 2, butuh 8
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-transactions/{$txId}/confirm")
            ->assertStatus(422)
            ->assertJsonPath('message', fn ($v) => str_contains($v, 'tidak mencukupi'));

        // Stok tetap 2, tidak berubah
        $this->assertEquals(2, (float) $product->fresh()->current_stock);
    }

    // ── 7. Transaksi confirmed tidak bisa diedit langsung ─────────────────

    public function test_transaksi_confirmed_tidak_bisa_diedit(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(0);

        $txId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stock-in', $this->stockInPayload($product->id, 5))
            ->json('transaction.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-transactions/{$txId}/confirm")
            ->assertStatus(200);

        // Edit harus ditolak
        $this->actingAs($user, 'sanctum')
            ->putJson("/api/v1/stock-transactions/{$txId}", ['note' => 'Ubah catatan'])
            ->assertStatus(403); // authorize() in UpdateStockTransactionRequest returns false
    }

    // ── 8. Konfirmasi berulang tidak menggandakan stok ───────────────────

    public function test_konfirmasi_berulang_tidak_menggandakan_stok(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(0);

        $txId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stock-in', $this->stockInPayload($product->id, 10))
            ->json('transaction.id');

        // Konfirmasi pertama
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-transactions/{$txId}/confirm")
            ->assertStatus(200);

        // Konfirmasi kedua — harus idempotent, tidak menambah stok lagi
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-transactions/{$txId}/confirm")
            ->assertStatus(200);

        $this->assertEquals(10, (float) $product->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 1); // hanya 1 movement
    }

    // ── 9. Idempotency key mencegah duplikasi transaksi ──────────────────

    public function test_idempotency_key_mencegah_duplikasi_konfirmasi(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(0);
        $iKey = 'test-ikey-'.uniqid();

        $txId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stock-in', array_merge(
                $this->stockInPayload($product->id, 10),
                ['idempotency_key' => $iKey]
            ))
            ->json('transaction.id');

        // Konfirmasi pertama — stok jadi 10
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-transactions/{$txId}/confirm")
            ->assertStatus(200);

        $this->assertEquals(10, (float) $product->fresh()->current_stock);

        // Konfirmasi kedua dengan key yang sama — harus return existing, stok tetap 10
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-transactions/{$txId}/confirm", ['idempotency_key' => $iKey])
            ->assertStatus(200)
            ->assertJsonPath('transaction.status', 'confirmed');

        $this->assertEquals(10, (float) $product->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 1);
    }

    // ── 10. Setiap konfirmasi menghasilkan stock_movements ───────────────

    public function test_konfirmasi_menghasilkan_stock_movements(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(0);

        $txId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stock-in', $this->stockInPayload($product->id, 15))
            ->json('transaction.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-transactions/{$txId}/confirm")
            ->assertStatus(200);

        $this->assertDatabaseCount('stock_movements', 1);

        $movement = StockMovement::first();
        $this->assertEquals(StockTransaction::TYPE_IN, $movement->movement_type);
        $this->assertEquals(15, (float) $movement->quantity);
        $this->assertEquals(0, (float) $movement->stock_before);
        $this->assertEquals(15, (float) $movement->stock_after);
        $this->assertEquals(StockTransaction::class, $movement->source_type);
        $this->assertEquals($txId, $movement->source_id);
    }

    // ── 11. stock_before dan stock_after konsisten ───────────────────────

    public function test_stock_before_dan_after_konsisten(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(20);

        $txId = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stock-out', $this->stockOutPayload($product->id, 7))
            ->json('transaction.id');

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-transactions/{$txId}/confirm");

        $item = StockTransactionItem::where('stock_transaction_id', $txId)->first();
        $this->assertEquals(20, (float) $item->stock_before);
        $this->assertEquals(13, (float) $item->stock_after);

        $movement = StockMovement::first();
        $this->assertEquals(20, (float) $movement->stock_before);
        $this->assertEquals(13, (float) $movement->stock_after);
        $this->assertEquals(-7, (float) $movement->quantity);
    }

    // ── 12. Dua konfirmasi bersamaan tidak membuat stok negatif ──────────

    public function test_konfirmasi_bersamaan_tidak_membuat_stok_negatif(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(5);

        // Buat dua draft stock_out masing-masing 4 (total 8, stok hanya 5)
        $tx1 = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stock-out', $this->stockOutPayload($product->id, 4))
            ->json('transaction.id');

        $tx2 = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stock-out', $this->stockOutPayload($product->id, 4))
            ->json('transaction.id');

        // Konfirmasi pertama berhasil (stok jadi 1)
        $res1 = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-transactions/{$tx1}/confirm");
        $res1->assertStatus(200);
        $this->assertEquals(1, (float) $product->fresh()->current_stock);

        // Konfirmasi kedua harus gagal (stok 1 < 4)
        $res2 = $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-transactions/{$tx2}/confirm");
        $res2->assertStatus(422)
            ->assertJsonPath('message', fn ($v) => str_contains($v, 'tidak mencukupi'));

        // Stok tetap 1, tidak negatif
        $this->assertEquals(1, (float) $product->fresh()->current_stock);
    }

    // ── 13. admin_kasir tidak bisa akses transaksi gudang ────────────────

    public function test_admin_kasir_tidak_bisa_akses_transaksi_gudang(): void
    {
        $kasir = $this->kasir();
        $product = $this->productWithStock(10);

        $this->actingAs($kasir, 'sanctum')
            ->getJson('/api/v1/stock-transactions')
            ->assertStatus(403);

        $this->actingAs($kasir, 'sanctum')
            ->postJson('/api/v1/stock-in', $this->stockInPayload($product->id, 5))
            ->assertStatus(403);

        $this->actingAs($kasir, 'sanctum')
            ->postJson('/api/v1/stock-out', $this->stockOutPayload($product->id, 5))
            ->assertStatus(403);
    }

    // ── 14. Barang nonaktif ditolak + pagination/filter list ─────────────

    public function test_barang_nonaktif_ditolak_dan_list_filter_bekerja(): void
    {
        $user = $this->gudang();

        // Barang nonaktif harus ditolak
        $inactive = $this->productWithStock(0, ['is_active' => false]);
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stock-in', $this->stockInPayload($inactive->id, 5))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['items.0.product_id']);

        // Buat beberapa transaksi untuk filter test
        $product = $this->productWithStock(100);
        for ($i = 0; $i < 3; $i++) {
            $txId = $this->actingAs($user, 'sanctum')
                ->postJson('/api/v1/stock-in', $this->stockInPayload($product->id, 1))
                ->json('transaction.id');

            if ($i === 0) {
                $this->actingAs($user, 'sanctum')
                    ->postJson("/api/v1/stock-transactions/{$txId}/confirm");
            }
        }

        // Filter by status=draft → harus dapat 2
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/stock-transactions?status=draft')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 2);

        // Filter by status=confirmed → harus dapat 1
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/stock-transactions?status=confirmed')
            ->assertStatus(200)
            ->assertJsonPath('meta.total', 1);

        // Pagination: per_page=2 dari 3 total
        $this->actingAs($user, 'sanctum')
            ->getJson('/api/v1/stock-transactions?per_page=2')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('meta.total', 3);
    }
}
