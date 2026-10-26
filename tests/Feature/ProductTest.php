<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductPriceHistory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Product API — 22 scenarios.
 *
 * Routes under test:
 *   GET    /api/v1/items
 *   POST   /api/v1/items
 *   GET    /api/v1/items/{item}
 *   PUT    /api/v1/items/{item}
 *   DELETE /api/v1/items/{item}
 *   GET    /api/v1/items/search
 */
class ProductTest extends TestCase
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

    private function category(): Category
    {
        return Category::factory()->create();
    }

    private function storePayload(array $overrides = []): array
    {
        $category = $overrides['category_id'] ?? $this->category()->id;

        return array_merge([
            'name' => 'Semen Tiga Roda',
            'category_id' => $category,
            'unit' => 'sak',
            'selling_price' => '85000.00',
            'purchase_price' => '75000.00',
            'min_stock' => '10.000',
        ], $overrides);
    }

    // ── 1. List barang (pagination) ───────────────────────────────────────

    public function test_list_barang_dengan_pagination(): void
    {
        $cat = $this->category();
        Product::factory()->forCategory($cat)->count(20)->create();

        $response = $this->actingAs($this->gudang(), 'sanctum')
            ->getJson('/api/v1/items?per_page=5')
            ->assertStatus(200);

        $this->assertCount(5, $response->json('data'));
        $this->assertSame(20, $response->json('meta.total'));
    }

    // ── 2. Tanpa token ditolak ────────────────────────────────────────────

    public function test_tanpa_token_ditolak(): void
    {
        $this->getJson('/api/v1/items')->assertStatus(401);
        $this->postJson('/api/v1/items', [])->assertStatus(401);
    }

    // ── 3. Tambah barang — stok awal selalu nol ───────────────────────────

    public function test_tambah_barang_stok_awal_selalu_nol(): void
    {
        $payload = $this->storePayload(['current_stock' => 50]); // should be ignored

        $response = $this->actingAs($this->gudang(), 'sanctum')
            ->postJson('/api/v1/items', $payload)
            ->assertStatus(201);

        // current_stock must be 0 regardless of what was sent
        // Check using numeric comparison (cast may return "0.000", 0, or "0")
        $currentStock = $response->json('item.current_stock');
        $this->assertEquals(0, (float) $currentStock);
        $this->assertDatabaseHas('products', [
            'name' => 'Semen Tiga Roda',
            'current_stock' => 0,
        ]);
    }

    // ── 4. Kode auto-generate jika tidak disediakan ───────────────────────

    public function test_kode_barang_digenerate_otomatis(): void
    {
        $response = $this->actingAs($this->gudang(), 'sanctum')
            ->postJson('/api/v1/items', $this->storePayload())
            ->assertStatus(201);

        $code = $response->json('item.code');
        $this->assertNotNull($code);
        $this->assertStringStartsWith('PRD-', $code);
    }

    // ── 5. Kode manual diterima dan unik ─────────────────────────────────

    public function test_kode_manual_diterima_dan_duplikasi_ditolak(): void
    {
        $cat = $this->category();

        $this->actingAs($this->gudang(), 'sanctum')
            ->postJson('/api/v1/items', $this->storePayload(['code' => 'CUSTOM-001', 'category_id' => $cat->id]))
            ->assertStatus(201);

        // Second with same code → 422
        $this->actingAs($this->gudang(), 'sanctum')
            ->postJson('/api/v1/items', $this->storePayload(['code' => 'CUSTOM-001', 'category_id' => $cat->id, 'name' => 'Lain']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['code']);
    }

    // ── 6. Barcode unik ────────────────────────────────────────────────────

    public function test_barcode_unik_ditegakkan(): void
    {
        $cat = $this->category();

        $this->actingAs($this->gudang(), 'sanctum')
            ->postJson('/api/v1/items', $this->storePayload(['barcode' => '1234567890123', 'category_id' => $cat->id]))
            ->assertStatus(201);

        $this->actingAs($this->gudang(), 'sanctum')
            ->postJson('/api/v1/items', $this->storePayload(['barcode' => '1234567890123', 'category_id' => $cat->id, 'name' => 'Lain', 'code' => 'X-002']))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['barcode']);
    }

    // ── 7. Kategori nonaktif ditolak ──────────────────────────────────────

    public function test_kategori_nonaktif_tidak_bisa_digunakan(): void
    {
        $inactive = Category::factory()->inactive()->create();

        $this->actingAs($this->gudang(), 'sanctum')
            ->postJson('/api/v1/items', $this->storePayload(['category_id' => $inactive->id]))
            ->assertStatus(422)
            ->assertJsonValidationErrors(['category_id']);
    }

    // ── 8. admin_kasir tidak boleh tambah barang ──────────────────────────

    public function test_admin_kasir_tidak_boleh_tambah_barang(): void
    {
        $this->actingAs($this->kasir(), 'sanctum')
            ->postJson('/api/v1/items', $this->storePayload())
            ->assertStatus(403);
    }

    // ── 9. Detail barang ──────────────────────────────────────────────────

    public function test_detail_barang_dapat_dilihat_kedua_role(): void
    {
        $product = Product::factory()->forCategory($this->category())->create(['name' => 'Besi Hollow']);

        foreach ([$this->gudang(), $this->kasir()] as $user) {
            $this->actingAs($user, 'sanctum')
                ->getJson("/api/v1/items/{$product->id}")
                ->assertStatus(200)
                ->assertJsonPath('item.name', 'Besi Hollow')
                ->assertJsonStructure(['item' => ['id', 'code', 'name', 'category', 'current_stock']]);
        }
    }

    // ── 10. Edit barang — current_stock diabaikan ─────────────────────────

    public function test_edit_barang_tidak_boleh_mengubah_current_stock(): void
    {
        $product = Product::factory()->forCategory($this->category())->create([
            'name' => 'Semen',
            'selling_price' => '85000',
            'purchase_price' => '70000',
        ]);
        // Force a non-zero stock directly in DB to simulate confirmed stock_in
        $product->getConnection()->table('products')
            ->where('id', $product->id)
            ->update(['current_stock' => 100]);

        $this->actingAs($this->gudang(), 'sanctum')
            ->putJson("/api/v1/items/{$product->id}", ['current_stock' => 999, 'name' => 'Semen Updated'])
            ->assertStatus(200);

        // Stock must remain at 100, not 999
        $this->assertDatabaseHas('products', ['id' => $product->id, 'current_stock' => 100]);
    }

    // ── 11. Riwayat harga dibuat saat harga berubah ───────────────────────

    public function test_riwayat_harga_dibuat_saat_harga_berubah(): void
    {
        $product = Product::factory()->forCategory($this->category())->create([
            'selling_price' => '85000.00',
            'purchase_price' => '70000.00',
        ]);

        $this->assertDatabaseCount('product_price_histories', 0);

        $this->actingAs($this->gudang(), 'sanctum')
            ->putJson("/api/v1/items/{$product->id}", [
                'selling_price' => '90000.00',
                'purchase_price' => '75000.00',
            ])
            ->assertStatus(200);

        $this->assertDatabaseCount('product_price_histories', 1);

        $history = ProductPriceHistory::first();
        $this->assertSame('85000.00', $history->old_selling_price);
        $this->assertSame('90000.00', $history->new_selling_price);
        $this->assertSame('70000.00', $history->old_purchase_price);
        $this->assertSame('75000.00', $history->new_purchase_price);
    }

    // ── 12. Tidak ada riwayat harga jika harga tidak berubah ─────────────

    public function test_tidak_ada_riwayat_harga_jika_tidak_berubah(): void
    {
        $product = Product::factory()->forCategory($this->category())->create([
            'selling_price' => '85000.00',
            'purchase_price' => '70000.00',
        ]);

        // Update hanya nama — harga tidak dikirim → no history
        $this->actingAs($this->gudang(), 'sanctum')
            ->putJson("/api/v1/items/{$product->id}", ['name' => 'Nama Baru'])
            ->assertStatus(200);

        $this->assertDatabaseCount('product_price_histories', 0);
    }

    // ── 13. Tidak ada riwayat harga jika nilai sama ───────────────────────

    public function test_tidak_ada_riwayat_harga_jika_nilai_sama(): void
    {
        $product = Product::factory()->forCategory($this->category())->create([
            'selling_price' => '85000.00',
            'purchase_price' => '70000.00',
        ]);

        // Send same price values → no history
        $this->actingAs($this->gudang(), 'sanctum')
            ->putJson("/api/v1/items/{$product->id}", [
                'selling_price' => '85000.00',
                'purchase_price' => '70000.00',
            ])
            ->assertStatus(200);

        $this->assertDatabaseCount('product_price_histories', 0);
    }

    // ── 14. Upload foto barang ────────────────────────────────────────────

    public function test_upload_foto_berhasil(): void
    {
        Storage::fake('public');

        $product = Product::factory()->forCategory($this->category())->create();

        $file = UploadedFile::fake()->image('foto.jpg', 100, 100);

        $response = $this->actingAs($this->gudang(), 'sanctum')
            ->putJson("/api/v1/items/{$product->id}", ['photo' => $file])
            ->assertStatus(200);

        $photoPath = $product->fresh()->photo_path;
        $this->assertNotNull($photoPath);
        Storage::disk('public')->assertExists($photoPath);
    }

    // ── 15. Validasi tipe dan ukuran foto ─────────────────────────────────

    public function test_validasi_foto_tipe_tidak_valid_ditolak(): void
    {
        Storage::fake('public');

        $product = Product::factory()->forCategory($this->category())->create();

        // PDF file disguised as image
        $badFile = UploadedFile::fake()->create('doc.pdf', 500, 'application/pdf');

        $this->actingAs($this->gudang(), 'sanctum')
            ->putJson("/api/v1/items/{$product->id}", ['photo' => $badFile])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['photo']);
    }

    // ── 16. Foto lama dihapus saat diganti ───────────────────────────────

    public function test_foto_lama_dihapus_saat_diganti(): void
    {
        Storage::fake('public');

        // Create fake old photo on disk
        Storage::disk('public')->put('products/old.jpg', 'fake-image-content');

        $product = Product::factory()->forCategory($this->category())->create([
            'photo_path' => 'products/old.jpg',
        ]);

        $newFile = UploadedFile::fake()->image('new.jpg', 100, 100);

        $this->actingAs($this->gudang(), 'sanctum')
            ->putJson("/api/v1/items/{$product->id}", ['photo' => $newFile])
            ->assertStatus(200);

        // Old file must be gone
        Storage::disk('public')->assertMissing('products/old.jpg');

        // New file must exist
        $newPath = $product->fresh()->photo_path;
        $this->assertNotNull($newPath);
        Storage::disk('public')->assertExists($newPath);
    }

    // ── 17. Nonaktifkan barang ────────────────────────────────────────────

    public function test_nonaktifkan_barang(): void
    {
        $product = Product::factory()->forCategory($this->category())->create();

        $this->actingAs($this->gudang(), 'sanctum')
            ->deleteJson("/api/v1/items/{$product->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Barang berhasil dinonaktifkan.');

        $this->assertDatabaseHas('products', ['id' => $product->id, 'is_active' => false]);
    }

    // ── 18. admin_kasir tidak boleh nonaktifkan ───────────────────────────

    public function test_admin_kasir_tidak_boleh_nonaktifkan_barang(): void
    {
        $product = Product::factory()->forCategory($this->category())->create();

        $this->actingAs($this->kasir(), 'sanctum')
            ->deleteJson("/api/v1/items/{$product->id}")
            ->assertStatus(403);
    }

    // ── 19. Pencarian berdasarkan nama, kode, barcode ─────────────────────

    public function test_pencarian_barang_berdasarkan_nama_kode_barcode(): void
    {
        $cat = $this->category();
        Product::factory()->forCategory($cat)->create(['name' => 'Semen Merah', 'code' => 'SEM-001', 'barcode' => null]);
        Product::factory()->forCategory($cat)->create(['name' => 'Besi Hollow', 'code' => 'BSI-001', 'barcode' => '1234567890123']);
        Product::factory()->forCategory($cat)->create(['name' => 'Cat Tembok', 'code' => 'CAT-001', 'barcode' => null]);

        // Search by name
        $this->actingAs($this->gudang(), 'sanctum')
            ->getJson('/api/v1/items/search?q=semen')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Semen Merah');

        // Search by code
        $this->actingAs($this->gudang(), 'sanctum')
            ->getJson('/api/v1/items/search?q=BSI')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        // Search by barcode (partial)
        $this->actingAs($this->gudang(), 'sanctum')
            ->getJson('/api/v1/items/search?q=12345')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    // ── 20. Pencarian tanpa q ditolak ─────────────────────────────────────

    public function test_pencarian_tanpa_parameter_q_ditolak(): void
    {
        $this->actingAs($this->gudang(), 'sanctum')
            ->getJson('/api/v1/items/search')
            ->assertStatus(422)
            ->assertJsonValidationErrors(['q']);
    }

    // ── 21. Filter kategori ───────────────────────────────────────────────

    public function test_filter_barang_berdasarkan_kategori(): void
    {
        $cat1 = Category::factory()->create(['name' => 'Kategori A']);
        $cat2 = Category::factory()->create(['name' => 'Kategori B']);

        Product::factory()->forCategory($cat1)->count(3)->create();
        Product::factory()->forCategory($cat2)->count(2)->create();

        $this->actingAs($this->gudang(), 'sanctum')
            ->getJson("/api/v1/items?category_id={$cat1->id}")
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    // ── 22. Filter kondisi stok ───────────────────────────────────────────

    public function test_filter_kondisi_stok(): void
    {
        $cat = $this->category();

        // out_of_stock: current_stock = 0
        $out = Product::factory()->forCategory($cat)->create(['min_stock' => '5.000']);
        // low_stock: 0 < current_stock <= min_stock
        $low = Product::factory()->forCategory($cat)->create(['min_stock' => '10.000']);
        $cat->getConnection()->table('products')->where('id', $low->id)->update(['current_stock' => 3]);
        // normal: current_stock > min_stock
        $normal = Product::factory()->forCategory($cat)->create(['min_stock' => '5.000']);
        $cat->getConnection()->table('products')->where('id', $normal->id)->update(['current_stock' => 50]);

        // out_of_stock filter
        $this->actingAs($this->gudang(), 'sanctum')
            ->getJson('/api/v1/items?stock_condition=out_of_stock')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $out->id);

        // low_stock filter
        $this->actingAs($this->gudang(), 'sanctum')
            ->getJson('/api/v1/items?stock_condition=low_stock')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $low->id);
    }
}
