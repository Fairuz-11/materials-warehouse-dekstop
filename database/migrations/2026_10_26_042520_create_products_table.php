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
     * current_stock is a denormalized cache of the latest stock_movement.stock_after.
     * It must ONLY be updated within the same DB transaction as the stock_movement,
     * using lockForUpdate on the product row. Never set current_stock directly;
     * all initial stock must go through a confirmed stock_in transaction.
     *
     * The CHECK constraint (current_stock >= 0) is a DB-level safety net against
     * negative stock. The application layer enforces this via lockForUpdate +
     * validation before any movement is recorded.
     */
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique();
            $table->string('name');
            $table->foreignId('category_id')->constrained()->restrictOnDelete();
            $table->string('unit', 30);
            $table->string('barcode', 100)->nullable()->unique();
            $table->string('brand', 100)->nullable();
            $table->decimal('purchase_price', 15, 2)->nullable();
            $table->decimal('selling_price', 15, 2)->nullable();
            $table->decimal('min_stock', 15, 3)->default(0);
            $table->decimal('current_stock', 15, 3)->default(0);
            $table->string('photo_path', 255)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index('is_active');
        });

        // DB-level safety net: prevent negative stock
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_current_stock_non_negative CHECK (current_stock >= 0)');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
