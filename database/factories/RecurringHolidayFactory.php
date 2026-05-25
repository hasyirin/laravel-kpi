<?php

namespace Hasyirin\KPI\Database\Factories;

use Hasyirin\KPI\Models\RecurringHoliday;
use Illuminate\Database\Eloquent\Factories\Factory;

class RecurringHolidayFactory extends Factory
{
    protected $model = RecurringHoliday::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'month' => fake()->numberBetween(1, 12),
            'day' => fake()->numberBetween(1, 28),
            'observes_substitute' => false,
            'effective_from' => null,
            'effective_until' => null,
        ];
    }
}
