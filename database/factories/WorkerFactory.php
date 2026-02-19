<?php

namespace Database\Factories;

use App\Models\Category;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class WorkerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id'             => User::factory(),
            'category_id'         => Category::factory(),
            'title'               => fake()->jobTitle(),
            'bio'                 => fake()->sentence(),
            'hourly_rate'         => fake()->numberBetween(5000, 50000),
            'availability_status' => 'inactive',
            'rating'              => 0,
            'rating_count'        => 0,
            'is_verified'         => false,
        ];
    }
}
