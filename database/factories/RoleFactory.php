<?php

namespace Database\Factories;

use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'name' => fake()->unique()->jobTitle(),
            'code' => fake()->unique()->slug(2),
            'is_active' => true,
        ];
    }
}
