<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * Alguien del equipo con el PIN 1234 (solo para pruebas).
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected static ?string $pinHash;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'role' => UserRole::Seller,
            'code' => 'TST-'.fake()->unique()->numerify('####'),
            'name' => fake()->firstName(),
            'title' => 'Ventas por comisión',
            'pin_hash' => static::$pinHash ??= Hash::make('1234'),
            'is_active' => true,
        ];
    }

    public function role(UserRole $role): static
    {
        return $this->state(fn () => ['role' => $role]);
    }

    /** Un cliente de la tienda web: sin código ni PIN. */
    public function customer(): static
    {
        return $this->state(fn () => [
            'role' => UserRole::Customer,
            'code' => null,
            'pin_hash' => null,
            'email' => fake()->unique()->safeEmail(),
        ]);
    }
}
