<?php

namespace Database\Factories;

use App\Enums\FloodImpactStatus;
use App\Models\Barangay;
use App\Models\FloodEvent;
use App\Models\FloodImpactReport;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FloodImpactReport>
 */
class FloodImpactReportFactory extends Factory
{
    protected $model = FloodImpactReport::class;

    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'barangay_id' => Barangay::factory(),
            'event_id' => FloodEvent::factory(),
            'families' => fake()->numberBetween(1, 20),
            'individuals' => fake()->numberBetween(1, 100),
            'reported_at' => now(),
            'status' => FloodImpactStatus::Submitted,
        ];
    }
}
