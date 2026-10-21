<?php

namespace Database\Factories;

use App\Enums\FloodLevel;
use App\Enums\FloodReportStatus;
use App\Models\Barangay;
use App\Models\FloodReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FloodReport>
 */
class FloodReportFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'barangay_id' => Barangay::factory(),
            'flood_level' => fake()->randomElement(FloodLevel::cases()),
            'latitude' => fake()->latitude(14.5, 14.7),
            'longitude' => fake()->longitude(120.9, 121.1),
            'gps_accuracy' => fake()->randomFloat(2, 1, 50),
            'photo_path' => 'flood-reports/'.fake()->uuid().'.jpg',
            'notes' => fake()->optional()->sentence(),
            'reported_at' => now(),
            'status' => FloodReportStatus::Submitted,
        ];
    }

    /**
     * Indicate that the report has been rejected.
     */
    public function rejected(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => FloodReportStatus::Rejected,
            'rejected_at' => now(),
            'rejection_reason' => 'Invalid report.',
        ]);
    }
}
