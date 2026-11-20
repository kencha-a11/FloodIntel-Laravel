<?php

namespace Database\Factories;

use App\Enums\SitrepSection;
use App\Models\FloodEvent;
use App\Models\SituationalReportSupplement;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SituationalReportSupplement>
 */
class SituationalReportSupplementFactory extends Factory
{
    protected $model = SituationalReportSupplement::class;

    public function definition(): array
    {
        return [
            'event_id' => FloodEvent::factory(),
            'section' => SitrepSection::Weather,
            'barangay_id' => null,
            'payload' => ['summary' => fake()->sentence()],
            'source' => 'MDRRMO field validation',
            'observed_at' => now(),
            'created_by_user_id' => User::factory()->staff(),
            'created_by_name' => fake()->name(),
        ];
    }

    public function section(SitrepSection $section): static
    {
        return $this->state(fn (): array => ['section' => $section]);
    }
}
