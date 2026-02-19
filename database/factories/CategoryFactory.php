<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class CategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'slug'         => fake()->unique()->slug(2),
            'display_name' => fake()->words(2, true),
            'icon'         => 'wrench',
            'color'        => '#3b82f6',
            'sort_order'   => fake()->numberBetween(1, 100),
            'is_active'    => true,
        ];
    }
}
