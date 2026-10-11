<?php

namespace Database\Factories;

use App\Enums\UserRole;
use App\Models\Barangay;
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
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'contact_number' => fake()->unique()->e164PhoneNumber(),
            'email_verified_at' => now(),
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'role' => UserRole::BarangayTanod,
            'barangay_id' => Barangay::factory(),
        ];
    }

    /**
     * Indicate that the model's email address should be unverified.
     */
    public function unverified(): static
    {
        return $this->state(fn (array $attributes): array => [
            'email_verified_at' => null,
        ]);
    }

    /**
     * Assign the given role, resetting the barangay requirement accordingly.
     */
    public function role(UserRole $role): static
    {
        return $this->state(fn (array $attributes): array => [
            'role' => $role,
            'barangay_id' => $role->requiresBarangay() ? Barangay::factory() : null,
        ]);
    }

    /**
     * Assign the user to a specific barangay.
     */
    public function forBarangay(Barangay|int $barangay): static
    {
        return $this->state(fn (array $attributes): array => [
            'barangay_id' => $barangay instanceof Barangay ? $barangay->id : $barangay,
        ]);
    }

    public function officer(): static
    {
        return $this->role(UserRole::MdrrmoOfficer);
    }

    public function staff(): static
    {
        return $this->role(UserRole::MdrrmoStaff);
    }

    public function captain(): static
    {
        return $this->role(UserRole::BarangayCaptain);
    }

    public function tanod(): static
    {
        return $this->role(UserRole::BarangayTanod);
    }

    public function rescueLeader(): static
    {
        return $this->role(UserRole::RescueLeader);
    }
}
