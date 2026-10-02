<?php

namespace Database\Factories;

use App\Enums\RoleEnum;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     * Default user receives ZERO roles per RBAC-DEC-08 and RBAC-DEC-10.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'email' => fake()->unique()->safeEmail(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'is_admin' => false,
            'status' => 'active',
        ];
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

    /**
     * Explicit factory state for Super Admin per RBAC-DEC-08.
     */
    public function superAdmin(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
            'status' => 'active',
        ])->afterCreating(function (User $user) {
            $user->assignRole(RoleEnum::SUPER_ADMIN->value);
        });
    }

    /**
     * Explicit factory state for Deliverability Operator per RBAC-DEC-08.
     */
    public function deliverabilityOperator(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
            'status' => 'active',
        ])->afterCreating(function (User $user) {
            $user->assignRole(RoleEnum::DELIVERABILITY_OPERATOR->value);
        });
    }

    /**
     * Explicit factory state for Customer Support per RBAC-DEC-08.
     */
    public function customerSupport(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
            'status' => 'active',
        ])->afterCreating(function (User $user) {
            $user->assignRole(RoleEnum::CUSTOMER_SUPPORT->value);
        });
    }

    /**
     * Explicit factory state for an administrator with zero roles (fail-closed) per RBAC-DEC-10.
     */
    public function withoutRoles(): static
    {
        return $this->state(fn (array $attributes) => [
            'is_admin' => true,
            'status' => 'active',
        ]);
    }
}
