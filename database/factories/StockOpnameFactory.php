<?php

namespace Database\Factories;

use App\Models\StockOpname;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockOpname>
 */
class StockOpnameFactory extends Factory
{
    private static int $seq = 0;

    public function definition(): array
    {
        self::$seq++;

        return [
            'opname_number' => 'OPN-TEST-'.str_pad((string) self::$seq, 5, '0', STR_PAD_LEFT),
            'opname_date' => now()->toDateString(),
            'status' => StockOpname::STATUS_DRAFT,
            'note' => null,
            'created_by' => User::factory()->adminGudang(),
            'confirmed_by' => null,
            'confirmed_at' => null,
            'idempotency_key' => null,
        ];
    }

    public function confirmed(): static
    {
        return $this->state(fn () => [
            'status' => StockOpname::STATUS_CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }

    public function cancelled(): static
    {
        return $this->state(fn () => ['status' => StockOpname::STATUS_CANCELLED]);
    }
}
