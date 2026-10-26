<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * stock_opname_id has a UNIQUE constraint (nullable) to enforce one-to-one:
     * one confirmed opname produces at most one adjustment transaction. Multiple
     * NULLs are allowed (transactions not from opnames).
     *
     * Draft transactions do not affect stock. Stock changes only when status
     * transitions to 'confirmed'. Confirmed transactions cannot be edited or
     * deleted directly; corrections require a new transaction with type =
     * 'correction' and a reason.
     *
     * Confirmation idempotency: the status field prevents re-processing.
     * The app must lockForUpdate, verify status is 'draft', then transition
     * within the same DB transaction. The idempotency_key column allows
     * API-level idempotency for the confirm endpoint.
     */
    public function up(): void
    {
        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('transaction_number', 50)->unique();
            $table->string('type', 20);
            $table->string('status', 20)->default('draft');
            $table->date('transaction_date');
            $table->text('note')->nullable();
            $table->text('reason')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('confirmed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('stock_opname_id')->nullable()->unique()->constrained('stock_opnames')->restrictOnDelete();
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->timestamps();

            $table->index(['type', 'status', 'transaction_date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('stock_transactions');
    }
};
