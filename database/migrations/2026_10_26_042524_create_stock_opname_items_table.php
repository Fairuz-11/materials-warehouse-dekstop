<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * system_stock is a snapshot of the product's current_stock at opname creation
     * time, stored for audit. It does NOT reflect changes that happen between
     * opname creation and confirmation.
     *
     * At confirmation time, the app must re-read the actual current_stock with
     * lockForUpdate and compute the adjustment as:
     *   adjustment = physical_stock - actual_current_stock_at_confirmation
     * This prevents incorrect final stock when other transactions occur during
     * the opname period.
     *
     * difference is a GENERATED STORED column: the database always computes it as
     * (physical_stock - system_stock), guaranteeing consistency without relying
     * on application logic.
     */
    public function up(): void
    {
        Schema::create('stock_opname_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_opname_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('system_stock', 15, 3);
            $table->decimal('physical_stock', 15, 3);
            $table->text('difference_reason')->nullable();

            $table->index(['product_id', 'stock_opname_id']);
        });

        // Generated column: difference is always computed by the database
        DB::statement('ALTER TABLE stock_opname_items ADD COLUMN difference DECIMAL(15,3) GENERATED ALWAYS AS (physical_stock - system_stock) STORED AFTER physical_stock');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_opname_items');
    }
};
