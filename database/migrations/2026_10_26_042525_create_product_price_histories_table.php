<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Immutable audit log: only created_at (no updated_at). Records every price
     * change with old and new values. restrictOnDelete on product_id and
     * changed_by ensures history survives deactivation.
     */
    public function up(): void
    {
        Schema::create('product_price_histories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('product_id')->constrained()->restrictOnDelete();
            $table->decimal('old_selling_price', 15, 2)->nullable();
            $table->decimal('new_selling_price', 15, 2)->nullable();
            $table->decimal('old_purchase_price', 15, 2)->nullable();
            $table->decimal('new_purchase_price', 15, 2)->nullable();
            $table->foreignId('changed_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['product_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_price_histories');
    }
};
