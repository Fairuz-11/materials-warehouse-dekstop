<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * stock_movements is the single source of truth for all stock changes.
     * Every movement must have a traceable source (source_type + source_id).
     *
     * source is polymorphic (morphs): supports stock_transaction now and sale
     * (future) without schema changes. Trade-off: no DB-level FK on source,
     * so the application must validate source existence within the same DB
     * transaction before creating a movement.
     *
     * quantity is signed: positive = stock_in, negative = stock_out.
     * stock_after = stock_before + quantity (always consistent).
     *
     * Immutable: only created_at (no updated_at). Movements are never updated.
     *
     * CONSISTENCY CONTRACT (enforced by a single stock service in the app layer):
     * 1. Begin DB transaction
     * 2. SELECT ... FOR UPDATE on the product row (lockForUpdate)
     * 3. Read current_stock as stock_before
     * 4. Compute stock_after = stock_before + signed_effect
     * 5. Validate stock_after >= 0 (reject if negative)
     * 6. Insert stock_movement with stock_before, stock_after
     * 7. Update products.current_stock = stock_after
     * 8. Commit transaction
     *
     * No other path may update products.current_stock.
     */
    public function up(): void
    {
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->morphs('source');
            $table->string('movement_type', 20);
            $table->decimal('quantity', 15, 3);
            $table->decimal('stock_before', 15, 3);
            $table->decimal('stock_after', 15, 3);
            $table->text('reason')->nullable();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
            $table->index('movement_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
    }
};
