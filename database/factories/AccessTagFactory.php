<?php

namespace Database\Factories;

use App\Enums\AccessTagType;
use App\Models\AccessTag;
use App\Models\Barangay;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessTag>
 */
class AccessTagFactory extends Factory
{
    protected $model = AccessTag::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'barangay_id' => Barangay::factory(),
            'created_by' => User::factory(),
            'type' => fake()->randomElement([
                AccessTagType::NarrowAlley,
                AccessTagType::NoVehicleAccess,
                AccessTagType::StairsOrSteep,
                AccessTagType::StructurallyBlocked,
            ]),
            'landmark' => fake()->optional()->sentence(3),
            'note' => null,
            'latitude' => fake()->latitude(14.5, 14.7),
            'longitude' => fake()->longitude(120.8, 120.9),
            'gps_accuracy' => fake()->randomFloat(2, 1, 50),
            'address' => fake()->optional()->streetAddress(),
            'observed_at' => now(),
            'is_active' => true,
        ];
    }

    /**
     * Indicate that the constraint does not fit the closed set (FR-112).
     */
    public function other(): static
    {
        return $this->state(fn (array $attributes): array => [
            'type' => AccessTagType::Other,
            'note' => fake()->sentence(),
        ]);
    }
}
