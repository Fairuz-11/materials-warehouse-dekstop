<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'role' => 'admin_gudang',
            'is_active' => true,
            'remember_token' => Str::random(10),
        ];
    }

    /**
     * User with role admin_gudang.
     */
    public function adminGudang(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin_gudang',
        ]);
    }

    /**
     * User with role admin_kasir.
     */
    public function adminKasir(): static
    {
        return $this->state(fn (array $attributes) => [
            'role' => 'admin_kasir',
        ]);
    }

    /**
     * Inactive user.
     */
    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_active' => false,
        ]);
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
