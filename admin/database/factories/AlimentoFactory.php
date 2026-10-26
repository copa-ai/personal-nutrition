<?php

namespace Database\Factories;

use App\Enums\CategoriaComida;
use App\Models\Alimento;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Alimento> */
class AlimentoFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nombre' => fake()->unique()->words(2, true),
            'categoria' => fake()->randomElement(CategoriaComida::cases()),
            'unidad' => 'g',
        ];
    }
}
