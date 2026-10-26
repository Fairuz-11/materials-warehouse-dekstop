<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Stores a snapshot of quantity and price at the time of the transaction.
     * Once the parent transaction is confirmed, items are immutable. Corrections
     * require a new transaction with type = 'correction'.
     *
     * quantity is signed: positive = stock_in, negative = stock_out.
     * stock_before and stock_after are recorded per item for audit.
     *
     * cascadeOnDelete on stock_transaction_id: items belong to their parent;
     * draft transactions can be deleted with their items.
     * restrictOnDelete on product_id: transaction history must survive product
     * deactivation.
     */
    public function up(): void
    {
        Schema::create('stock_transaction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('quantity', 15, 3);
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->decimal('total_purchase', 15, 2)->nullable();
            $table->decimal('stock_before', 15, 3);
            $table->decimal('stock_after', 15, 3);

            $table->index(['product_id', 'stock_transaction_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_transaction_items');
    }
};
