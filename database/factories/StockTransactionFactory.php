<?php

namespace Database\Factories;

use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockTransaction>
 */
class StockTransactionFactory extends Factory
{
    private static int $seq = 0;

    public function definition(): array
    {
        self::$seq++;

        return [
            'transaction_number' => 'TRX-TEST-'.str_pad((string) self::$seq, 5, '0', STR_PAD_LEFT),
            'type' => StockTransaction::TYPE_IN,
            'status' => StockTransaction::STATUS_DRAFT,
            'transaction_date' => now()->toDateString(),
            'note' => null,
            'reason' => null,
            'created_by' => User::factory()->adminGudang(),
            'confirmed_by' => null,
            'confirmed_at' => null,
            'idempotency_key' => null,
        ];
    }

    public function stockIn(): static
    {
        return $this->state(fn () => ['type' => StockTransaction::TYPE_IN]);
    }

    public function stockOut(): static
    {
        return $this->state(fn () => ['type' => StockTransaction::TYPE_OUT]);
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => StockTransaction::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => StockTransaction::STATUS_CANCELLED]);
    }
}
