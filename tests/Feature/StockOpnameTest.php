<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockMovement;
use App\Models\StockOpname;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockOpnameTest extends TestCase
{
    use RefreshDatabase;

    private function gudang(): User
    {
        return User::factory()->adminGudang()->create();
    }

    private function productWithStock(float $stock = 0): Product
    {
        $category = Category::factory()->create();
        $product = Product::factory()->forCategory($category)->create();

        $product->getConnection()
            ->table('products')
            ->where('id', $product->id)
            ->update(['current_stock' => $stock]);

        return $product->fresh();
    }

    private function createOpname(User $user, Product $product, ?float $physicalStock): StockOpname
    {
        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/v1/stock-opnames', [
                'opname_date' => now()->toDateString(),
                'note' => 'Tes stock opname',
                'items' => [
                    [
                        'product_id' => $product->id,
                        'physical_stock' => $physicalStock,
                    ],
                ],
            ]);

        $response->assertCreated();

        return StockOpname::findOrFail($response->json('data.id'));
    }

    public function test_stock_opname_dapat_dibuat_dan_stok_belum_berubah(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(10);

        $opname = $this->createOpname($user, $product, 7);

        $this->assertEquals(10, (float) $product->fresh()->current_stock);
        $this->assertEquals(StockOpname::STATUS_DRAFT, $opname->status);
        $this->assertDatabaseHas('stock_opname_items', [
            'stock_opname_id' => $opname->id,
            'product_id' => $product->id,
            'system_stock' => 10,
            'physical_stock' => 7,
        ]);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_konfirmasi_opname_mengurangi_stok_sesuai_hasil_fisik(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(10);
        $opname = $this->createOpname($user, $product, 7);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-opnames/{$opname->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', StockOpname::STATUS_CONFIRMED);

        $this->assertEquals(7, (float) $product->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 1);

        $movement = StockMovement::firstOrFail();

        $this->assertEquals(StockTransaction::TYPE_OPNAME, $movement->movement_type);
        $this->assertEquals(-3, (float) $movement->quantity);
        $this->assertEquals(10, (float) $movement->stock_before);
        $this->assertEquals(7, (float) $movement->stock_after);
        $this->assertEquals(StockTransaction::class, $movement->source_type);

        $this->assertDatabaseHas('stock_transactions', [
            'stock_opname_id' => $opname->id,
            'type' => StockTransaction::TYPE_OPNAME,
            'status' => StockTransaction::STATUS_CONFIRMED,
        ]);
    }

    public function test_konfirmasi_opname_dapat_menambah_stok(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(5);
        $opname = $this->createOpname($user, $product, 8);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-opnames/{$opname->id}/confirm")
            ->assertOk();

        $this->assertEquals(8, (float) $product->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 1);

        $movement = StockMovement::firstOrFail();
        $this->assertEquals(3, (float) $movement->quantity);
        $this->assertEquals(5, (float) $movement->stock_before);
        $this->assertEquals(8, (float) $movement->stock_after);
    }

    public function test_opname_tidak_bisa_dikonfirmasi_jika_ada_barang_belum_dihitung(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(10);
        $opname = $this->createOpname($user, $product, null);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-opnames/{$opname->id}/confirm")
            ->assertStatus(422);

        $this->assertEquals(10, (float) $product->fresh()->current_stock);
        $this->assertEquals(StockOpname::STATUS_DRAFT, $opname->fresh()->status);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_konfirmasi_opname_ulang_tidak_membuat_movement_duplikat(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(10);
        $opname = $this->createOpname($user, $product, 7);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-opnames/{$opname->id}/confirm")
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-opnames/{$opname->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', StockOpname::STATUS_CONFIRMED);

        $this->assertEquals(7, (float) $product->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 1);
        $this->assertDatabaseCount('stock_transactions', 1);
    }

    public function test_opname_yang_dibatalkan_tidak_bisa_dikonfirmasi(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(10);
        $opname = $this->createOpname($user, $product, 7);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-opnames/{$opname->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', StockOpname::STATUS_CANCELLED);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-opnames/{$opname->id}/confirm")
            ->assertStatus(403);

        $this->assertEquals(10, (float) $product->fresh()->current_stock);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    // ── Test 7: selisih nol tidak membuat movement ────────────────────────

    public function test_konfirmasi_opname_dengan_selisih_nol_tidak_membuat_movement(): void
    {
        $user = $this->gudang();
        $product = $this->productWithStock(10);

        // physical_stock == actual stock → difference = 0 exactly
        $opname = $this->createOpname($user, $product, 10);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-opnames/{$opname->id}/confirm")
            ->assertOk()
            ->assertJsonPath('data.status', StockOpname::STATUS_CONFIRMED);

        // Stok tidak berubah
        $this->assertEquals(10, (float) $product->fresh()->current_stock);

        // Tidak ada movement karena selisih = 0
        $this->assertDatabaseCount('stock_movements', 0);

        // StockTransactionItem tetap dibuat (audit trail), tapi quantity = 0
        $this->assertDatabaseHas('stock_transaction_items', [
            'product_id' => $product->id,
            'quantity' => 0,
            'stock_before' => 10,
            'stock_after' => 10,
        ]);
    }

    // ── Test 8: stok aktual bergerak antara opname dibuat dan dikonfirmasi ─

    public function test_konfirmasi_opname_menggunakan_stok_aktual_bukan_snapshot(): void
    {
        $user = $this->gudang();
        // Saat opname dibuat, stok = 10 → system_stock = 10
        $product = $this->productWithStock(10);
        $opname = $this->createOpname($user, $product, 8);

        // Verifikasi system_stock disimpan sebagai 10
        $this->assertDatabaseHas('stock_opname_items', [
            'stock_opname_id' => $opname->id,
            'system_stock' => 10,
        ]);

        // Simulasi transaksi lain mengubah stok menjadi 6 sebelum konfirmasi
        $product->getConnection()
            ->table('products')
            ->where('id', $product->id)
            ->update(['current_stock' => 6]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/v1/stock-opnames/{$opname->id}/confirm")
            ->assertOk();

        // Stok akhir harus physical_stock = 8, bukan 8 + (8-10) = 6
        $this->assertEquals(8, (float) $product->fresh()->current_stock);

        // Movement dihitung dari stok aktual saat konfirmasi (6), bukan snapshot (10)
        // quantity = physical - actual_at_confirm = 8 - 6 = +2
        $movement = StockMovement::firstOrFail();
        $this->assertEquals(2, (float) $movement->quantity);
        $this->assertEquals(6, (float) $movement->stock_before);
        $this->assertEquals(8, (float) $movement->stock_after);
    }
}
