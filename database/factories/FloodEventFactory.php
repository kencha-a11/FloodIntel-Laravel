<?php

namespace Database\Factories;

use App\Models\FloodEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FloodEvent>
 */
class FloodEventFactory extends Factory
{
    protected $model = FloodEvent::class;

    public function definition(): array
    {
        return [
            'title' => 'Flood event '.fake()->unique()->numerify('####'),
            'description' => fake()->sentence(),
            'starts_at' => now()->subHours(6),
            'ends_at' => null,
            'is_closed' => false,
        ];
    }

    public function closed(): static
    {
        return $this->state(fn (): array => [
            'ends_at' => now(),
            'is_closed' => true,
        ]);
    }
}
