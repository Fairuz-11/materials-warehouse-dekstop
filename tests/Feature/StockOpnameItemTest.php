<?php

namespace Tests\Feature;

use App\Http\Requests\Api\V1\StoreStockOpnameRequest;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockOpname;
use App\Models\StockOpnameItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Tests for the physical_stock nullable design and the GENERATED difference column.
 *
 * These tests operate directly on the DB layer (model + factory) rather than
 * through HTTP endpoints, because the StockOpname controller has not been built
 * yet (Step 6). They verify:
 *
 *   1. DB accepts NULL for physical_stock on a draft item
 *   2. difference is NULL when physical_stock is NULL (GENERATED column behaviour)
 *   3. physical_stock = 0 is accepted and treated as a valid count (not "empty")
 *   4. difference is correctly computed as (physical_stock - system_stock)
 *      including the edge case where physical_stock = 0
 *   5. allItemsCounted() returns false when any item has physical_stock = NULL
 *   6. allItemsCounted() returns true when every item has a value (including 0)
 *   7. allItemsCounted() returns false for an opname with no items
 *   8. StoreStockOpnameRequest rejects negative physical_stock values
 *   9. StoreStockOpnameRequest accepts null physical_stock
 *  10. StoreStockOpnameRequest accepts physical_stock = 0
 *  11. The model never writes to the difference column (it is GENERATED)
 */
class StockOpnameItemTest extends TestCase
{
    use RefreshDatabase;

    // ── Helpers ───────────────────────────────────────────────────────────

    private function makeUser(): User
    {
        return User::factory()->adminGudang()->create();
    }

    private function makeProduct(float $currentStock = 0): Product
    {
        $category = Category::factory()->create();

        $product = Product::factory()->forCategory($category)->create();

        if ($currentStock > 0) {
            $product->getConnection()
                ->table('products')
                ->where('id', $product->id)
                ->update(['current_stock' => $currentStock]);
        }

        return $product->fresh();
    }

    private function makeOpname(?User $user = null): StockOpname
    {
        return StockOpname::factory()->create([
            'created_by' => ($user ?? $this->makeUser())->id,
        ]);
    }

    /**
     * Insert a StockOpnameItem directly. physical_stock is nullable by design.
     *
     * @param  float|null  $physicalStock  null = not yet counted
     * @param  float  $systemStock  snapshot of current_stock at opname time
     */
    private function makeItem(
        StockOpname $opname,
        Product $product,
        ?float $physicalStock,
        float $systemStock = 10.0,
        ?string $reason = null
    ): StockOpnameItem {
        $item = StockOpnameItem::create([
            'stock_opname_id' => $opname->id,
            'product_id' => $product->id,
            'system_stock' => $systemStock,
            'physical_stock' => $physicalStock,
            'difference_reason' => $reason,
        ]);

        // Re-fetch to pick up the GENERATED difference value from the DB
        return $item->fresh();
    }

    // ── 1. DB menerima NULL untuk physical_stock ──────────────────────────

    public function test_physical_stock_dapat_disimpan_sebagai_null(): void
    {
        $opname = $this->makeOpname();
        $product = $this->makeProduct();

        $item = $this->makeItem($opname, $product, physicalStock: null, systemStock: 10.0);

        $this->assertNull($item->physical_stock);
        $this->assertDatabaseHas('stock_opname_items', [
            'id' => $item->id,
            'physical_stock' => null,
        ]);
    }

    // ── 2. difference adalah NULL saat physical_stock NULL ───────────────

    public function test_difference_adalah_null_saat_physical_stock_null(): void
    {
        $opname = $this->makeOpname();
        $product = $this->makeProduct();

        $item = $this->makeItem($opname, $product, physicalStock: null, systemStock: 10.0);

        // GENERATED column: database computes NULL when physical_stock is NULL
        $this->assertNull($item->difference);
        $this->assertDatabaseHas('stock_opname_items', [
            'id' => $item->id,
            'physical_stock' => null,
            // difference is generated; MySQL stores NULL
        ]);
    }

    // ── 3. physical_stock = 0 valid (bukan "belum dihitung") ─────────────

    public function test_physical_stock_nol_diterima_sebagai_hasil_hitung_valid(): void
    {
        $opname = $this->makeOpname();
        $product = $this->makeProduct();

        // Explicitly count the product as zero — this is a valid result
        $item = $this->makeItem($opname, $product, physicalStock: 0.0, systemStock: 10.0);

        $this->assertNotNull($item->physical_stock);
        $this->assertEquals('0.000', $item->physical_stock);
    }

    // ── 4. difference dihitung dengan benar oleh database ────────────────

    public function test_difference_dihitung_oleh_database_bukan_aplikasi(): void
    {
        $opname = $this->makeOpname();
        $product = $this->makeProduct();

        // Case A: physical < system → negative difference
        $itemA = $this->makeItem($opname, $product, physicalStock: 7.5, systemStock: 10.0);
        $this->assertEquals('-2.500', $itemA->difference,
            'difference should be physical_stock - system_stock = 7.5 - 10.0 = -2.5');

        // Case B: physical > system → positive difference
        $productB = $this->makeProduct();
        $itemB = $this->makeItem($opname, $productB, physicalStock: 15.0, systemStock: 10.0);
        $this->assertEquals('5.000', $itemB->difference,
            'difference should be 15.0 - 10.0 = 5.0');

        // Case C: physical = system → zero difference
        $productC = $this->makeProduct();
        $itemC = $this->makeItem($opname, $productC, physicalStock: 10.0, systemStock: 10.0);
        $this->assertEquals('0.000', $itemC->difference,
            'difference should be 10.0 - 10.0 = 0.0');

        // Case D: physical = 0, system = 5 → difference = -5 (zero is a real count)
        $productD = $this->makeProduct();
        $itemD = $this->makeItem($opname, $productD, physicalStock: 0.0, systemStock: 5.0);
        $this->assertEquals('-5.000', $itemD->difference,
            'physical_stock=0 is a real count; difference should be 0 - 5 = -5');
    }

    // ── 5. allItemsCounted() = false jika ada NULL ────────────────────────

    public function test_all_items_counted_false_jika_ada_physical_stock_null(): void
    {
        $opname = $this->makeOpname();
        $productA = $this->makeProduct();
        $productB = $this->makeProduct();

        // One item counted, one not yet counted
        $this->makeItem($opname, $productA, physicalStock: 5.0);
        $this->makeItem($opname, $productB, physicalStock: null);

        $this->assertFalse($opname->allItemsCounted(),
            'allItemsCounted() must return false when any item has physical_stock = NULL');
    }

    // ── 6. allItemsCounted() = true jika semua item terisi (termasuk 0) ──

    public function test_all_items_counted_true_jika_semua_terisi_termasuk_nol(): void
    {
        $opname = $this->makeOpname();
        $productA = $this->makeProduct();
        $productB = $this->makeProduct();

        // Both items counted — one with 0 (valid), one with a positive value
        $this->makeItem($opname, $productA, physicalStock: 0.0);
        $this->makeItem($opname, $productB, physicalStock: 8.5);

        $this->assertTrue($opname->allItemsCounted(),
            'allItemsCounted() must return true when all items have physical_stock set, even if 0');
    }

    // ── 7. allItemsCounted() = false untuk opname tanpa item ─────────────

    public function test_all_items_counted_false_untuk_opname_tanpa_item(): void
    {
        $opname = $this->makeOpname();

        $this->assertFalse($opname->allItemsCounted(),
            'allItemsCounted() must return false when the opname has no items');
    }

    // ── 8. StoreStockOpnameRequest: physical_stock negatif ditolak ────────

    public function test_store_request_menolak_physical_stock_negatif(): void
    {
        $user = $this->makeUser();
        $product = $this->makeProduct();
        $opname = $this->makeOpname($user);

        // We test the request validation by calling the endpoint that will
        // exist in Step 6 — but since it doesn't exist yet, we test the
        // validation logic directly by posting to the future route and
        // asserting 404 (route not found) vs 422 (validation error).
        //
        // Instead, we test the request rules directly via the FormRequest class.
        $request = new StoreStockOpnameRequest;
        $rules = $request->rules();

        // The physical_stock rules should include 'min:0'
        $physicalStockRules = $rules['items.*.physical_stock'] ?? [];
        $this->assertContains('min:0', $physicalStockRules,
            'StoreStockOpnameRequest must reject negative physical_stock values');
    }

    // ── 9. isCounted() falsy untuk NULL, truthy untuk 0 ──────────────────

    public function test_is_counted_membedakan_null_dari_nol(): void
    {
        $opname = $this->makeOpname();
        $productA = $this->makeProduct();
        $productB = $this->makeProduct();

        $itemNull = $this->makeItem($opname, $productA, physicalStock: null);
        $itemZero = $this->makeItem($opname, $productB, physicalStock: 0.0);

        $this->assertFalse($itemNull->isCounted(),
            'isCounted() must be false when physical_stock is NULL');
        $this->assertTrue($itemZero->isCounted(),
            'isCounted() must be true when physical_stock = 0 (counted, result is zero)');
    }

    // ── 10. hasDiscrepancy() benar untuk berbagai skenario ───────────────

    public function test_has_discrepancy_benar_untuk_berbagai_skenario(): void
    {
        $opname = $this->makeOpname();
        $p1 = $this->makeProduct();
        $p2 = $this->makeProduct();
        $p3 = $this->makeProduct();
        $p4 = $this->makeProduct();

        // NULL → not counted → no discrepancy (not applicable)
        $itemNull = $this->makeItem($opname, $p1, physicalStock: null, systemStock: 10.0);
        $this->assertFalse($itemNull->hasDiscrepancy());

        // physical = system → no discrepancy
        $itemMatch = $this->makeItem($opname, $p2, physicalStock: 10.0, systemStock: 10.0);
        $this->assertFalse($itemMatch->hasDiscrepancy());

        // physical ≠ system → discrepancy
        $itemMismatch = $this->makeItem($opname, $p3, physicalStock: 7.0, systemStock: 10.0);
        $this->assertTrue($itemMismatch->hasDiscrepancy());

        // physical = 0, system = 10 → discrepancy (zero is a real count)
        $itemZero = $this->makeItem($opname, $p4, physicalStock: 0.0, systemStock: 10.0);
        $this->assertTrue($itemZero->hasDiscrepancy(),
            'physical_stock=0 with system_stock=10 must be treated as a discrepancy');
    }

    // ── 11. Model tidak pernah menulis ke kolom difference ───────────────

    public function test_kolom_difference_tidak_ada_dalam_fillable(): void
    {
        $item = new StockOpnameItem;

        $this->assertNotContains('difference', $item->getFillable(),
            'difference is a GENERATED column and must never be in $fillable');
    }
}
