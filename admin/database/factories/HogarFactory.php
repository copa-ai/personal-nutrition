<?php

namespace Database\Factories;

use App\Models\Hogar;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Hogar> */
class HogarFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['nombre' => 'Casa '.fake()->lastName()];
    }
}
