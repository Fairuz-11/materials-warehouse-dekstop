<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Category API — 16 scenarios.
 *
 * Routes under test:
 *   GET    /api/v1/categories
 *   POST   /api/v1/categories
 *   GET    /api/v1/categories/{category}
 *   PUT    /api/v1/categories/{category}
 *   DELETE /api/v1/categories/{category}
 */
class CategoryTest extends TestCase
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

    // ── 1. List kategori (admin_gudang lihat semua) ───────────────────────

    public function test_admin_gudang_dapat_melihat_semua_kategori(): void
    {
        Category::factory()->count(3)->create();
        Category::factory()->inactive()->count(2)->create();

        $this->actingAs($this->gudang(), 'sanctum')
            ->getJson('/api/v1/categories')
            ->assertStatus(200)
            ->assertJsonCount(5, 'data')
            ->assertJsonStructure(['data', 'links', 'meta']);
    }

    // ── 2. List kategori (admin_kasir hanya aktif) ────────────────────────

    public function test_admin_kasir_hanya_melihat_kategori_aktif(): void
    {
        Category::factory()->count(3)->create();
        Category::factory()->inactive()->count(2)->create();

        $this->actingAs($this->kasir(), 'sanctum')
            ->getJson('/api/v1/categories')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    // ── 3. Filter is_active (admin_gudang) ────────────────────────────────

    public function test_admin_gudang_dapat_filter_is_active(): void
    {
        Category::factory()->count(2)->create();
        Category::factory()->inactive()->count(3)->create();

        $this->actingAs($this->gudang(), 'sanctum')
            ->getJson('/api/v1/categories?is_active=false')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    // ── 4. Tanpa token ditolak ────────────────────────────────────────────

    public function test_tanpa_token_ditolak(): void
    {
        $this->getJson('/api/v1/categories')->assertStatus(401);
        $this->postJson('/api/v1/categories', [])->assertStatus(401);
    }

    // ── 5. Tambah kategori berhasil ───────────────────────────────────────

    public function test_admin_gudang_dapat_tambah_kategori(): void
    {
        $response = $this->actingAs($this->gudang(), 'sanctum')
            ->postJson('/api/v1/categories', [
                'name' => 'Semen',
                'description' => 'Material semen dan grouting',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('category.name', 'Semen');

        $this->assertDatabaseHas('categories', ['name' => 'Semen', 'is_active' => 1]);

        $this->assertDatabaseHas('categories', ['name' => 'Semen']);
    }

    // ── 6. Normalisasi spasi pada nama ────────────────────────────────────

    public function test_nama_kategori_dinormalisasi_sebelum_disimpan(): void
    {
        $response = $this->actingAs($this->gudang(), 'sanctum')
            ->postJson('/api/v1/categories', ['name' => '  Besi   Beton  ']);

        $response->assertStatus(201)
            ->assertJsonPath('category.name', 'Besi Beton');

        $this->assertDatabaseHas('categories', ['name' => 'Besi Beton']);
        $this->assertDatabaseMissing('categories', ['name' => '  Besi   Beton  ']);
    }

    // ── 7. Duplikasi nama ditolak ─────────────────────────────────────────

    public function test_duplikasi_nama_kategori_ditolak(): void
    {
        Category::factory()->create(['name' => 'Pasir']);

        $this->actingAs($this->gudang(), 'sanctum')
            ->postJson('/api/v1/categories', ['name' => 'Pasir'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['name']);
    }

    // ── 8. admin_kasir tidak boleh tambah kategori ────────────────────────

    public function test_admin_kasir_tidak_boleh_tambah_kategori(): void
    {
        $this->actingAs($this->kasir(), 'sanctum')
            ->postJson('/api/v1/categories', ['name' => 'Cat'])
            ->assertStatus(403);
    }

    // ── 9. Detail kategori ────────────────────────────────────────────────

    public function test_detail_kategori_dapat_dilihat(): void
    {
        $category = Category::factory()->create(['name' => 'Keramik']);

        $this->actingAs($this->gudang(), 'sanctum')
            ->getJson("/api/v1/categories/{$category->id}")
            ->assertStatus(200)
            ->assertJsonPath('category.name', 'Keramik')
            ->assertJsonStructure(['category' => ['id', 'name', 'is_active', 'products_count']]);
    }

    // ── 10. Update kategori ───────────────────────────────────────────────

    public function test_admin_gudang_dapat_update_kategori(): void
    {
        $category = Category::factory()->create(['name' => 'Lama']);

        $this->actingAs($this->gudang(), 'sanctum')
            ->putJson("/api/v1/categories/{$category->id}", ['name' => 'Baru'])
            ->assertStatus(200)
            ->assertJsonPath('category.name', 'Baru');

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'name' => 'Baru']);
    }

    // ── 11. Update nama ke nilai yang sama tidak error unique ─────────────

    public function test_update_nama_yang_sama_tidak_error_unique(): void
    {
        $category = Category::factory()->create(['name' => 'Sama']);

        $this->actingAs($this->gudang(), 'sanctum')
            ->putJson("/api/v1/categories/{$category->id}", ['name' => 'Sama'])
            ->assertStatus(200);
    }

    // ── 12. admin_kasir tidak boleh update ────────────────────────────────

    public function test_admin_kasir_tidak_boleh_update_kategori(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->kasir(), 'sanctum')
            ->putJson("/api/v1/categories/{$category->id}", ['name' => 'Ubah'])
            ->assertStatus(403);
    }

    // ── 13. Nonaktifkan kategori tanpa produk ─────────────────────────────

    public function test_nonaktifkan_kategori_tanpa_produk(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->gudang(), 'sanctum')
            ->deleteJson("/api/v1/categories/{$category->id}")
            ->assertStatus(200)
            ->assertJsonPath('message', 'Kategori berhasil dinonaktifkan.');

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'is_active' => false]);
    }

    // ── 14. Nonaktifkan kategori yang masih memiliki produk ───────────────

    public function test_nonaktifkan_kategori_dengan_produk_memberikan_pesan_sesuai(): void
    {
        $category = Category::factory()->create();
        Product::factory()->forCategory($category)->create();

        $response = $this->actingAs($this->gudang(), 'sanctum')
            ->deleteJson("/api/v1/categories/{$category->id}");

        $response->assertStatus(200)
            ->assertJsonPath('message', 'Kategori memiliki barang terkait dan telah dinonaktifkan.');

        $this->assertDatabaseHas('categories', ['id' => $category->id, 'is_active' => false]);
        // Product still references the category — FK intact
        $this->assertDatabaseHas('products', ['category_id' => $category->id]);
    }

    // ── 15. admin_kasir tidak boleh menghapus/nonaktifkan ────────────────

    public function test_admin_kasir_tidak_boleh_nonaktifkan_kategori(): void
    {
        $category = Category::factory()->create();

        $this->actingAs($this->kasir(), 'sanctum')
            ->deleteJson("/api/v1/categories/{$category->id}")
            ->assertStatus(403);
    }

    // ── 16. Pagination ────────────────────────────────────────────────────

    public function test_list_kategori_menggunakan_pagination(): void
    {
        Category::factory()->count(20)->create();

        $response = $this->actingAs($this->gudang(), 'sanctum')
            ->getJson('/api/v1/categories?per_page=5')
            ->assertStatus(200);

        $this->assertCount(5, $response->json('data'));
        $this->assertSame(20, $response->json('meta.total'));
    }
}
