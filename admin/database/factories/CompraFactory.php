<?php

namespace Database\Factories;

use App\Models\Compra;
use App\Models\Hogar;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Compra> */
class CompraFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['hogar_id' => Hogar::factory(), 'fecha' => fake()->date(), 'tienda' => fake()->company(), 'notas' => null];
    }
}
