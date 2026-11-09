<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class InstitutionFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->company(),
            'short_name' => fake()->unique()->lexify('Kurum ???'),
            'is_active' => true,
        ];
    }
}
