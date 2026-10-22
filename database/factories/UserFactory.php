<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Test users: active, allowed to log in, with their own password already chosen.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The password every factory user shares (hashed once per test run).
     */
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
            'password' => static::$password ??= Hash::make('password'),
            'must_change_password' => false,
        ];
    }

    /**
     * A user whose password was set by an administrator and must be changed at first sign-in.
     */
    public function withTemporaryPassword(): static
    {
        return $this->state(fn () => ['must_change_password' => true]);
    }
}
