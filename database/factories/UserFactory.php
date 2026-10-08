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

    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'gender' => fake()->randomElement(['M', 'F']),
            'address' => fake()->randomElement([
                'دمشق', 'حلب', 'حمص', 'حماة', 'اللاذقية', 'درعا',
            ]),
            'status' => 1,
            'verification_status' => 'none',
            // R2 sec 119 (owner decision 2026-10-11): this was 0, so every factory user existed in a
            // state NO production row is ever in - `2026_05_10_172303_add_token_version_to_users_table`
            // defaults this column to 1. The suite was therefore exercising a value the database
            // never produces.
            //
            // This is a FIDELITY gap, not a security hole, and the distinction matters: `JwtService:87`
            // is `if (! isset($payload['ver'])) return false;`, so the user path fails closed, and
            // `:91` compares against the `ver` claim minted at `:270`, which means ANY starting value is
            // self-consistent. Employees default 0 on purpose (`2026_05_15_230503`) and are NOT
            // changed here - each table is pinned to its own schema default, not to a shared number.
            'token_version' => 1,
        ];
    }

    public function unverified(): static
    {
        return $this->state(fn (array $attributes) => [
            'email_verified_at' => null,
        ]);
    }
}
