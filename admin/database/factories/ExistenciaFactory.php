<?php

namespace Database\Factories;

use App\Models\Alimento;
use App\Models\Existencia;
use App\Models\Hogar;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Existencia> */
class ExistenciaFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'hogar_id' => Hogar::factory(),
            'alimento_id' => Alimento::factory(),
            'cantidad' => fake()->randomFloat(2, 100, 2000),
            'unidad' => 'g',
            'caduca_el' => null,
        ];
    }
}
