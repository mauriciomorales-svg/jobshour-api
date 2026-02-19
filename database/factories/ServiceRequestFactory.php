<?php

namespace Database\Factories;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Factories\Factory;

class ServiceRequestFactory extends Factory
{
    public function definition(): array
    {
        return [
            'client_id'     => User::factory(),
            'worker_id'     => Worker::factory(),
            'description'   => fake()->sentence(),
            'status'        => 'accepted',
            'offered_price' => fake()->numberBetween(5000, 50000),
        ];
    }
}
