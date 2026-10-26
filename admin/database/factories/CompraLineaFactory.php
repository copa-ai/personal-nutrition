<?php

namespace Database\Factories;

use App\Models\Alimento;
use App\Models\Compra;
use App\Models\CompraLinea;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CompraLinea> */
class CompraLineaFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'compra_id' => Compra::factory(),
            'alimento_id' => Alimento::factory(),
            'cantidad' => fake()->randomFloat(2, 100, 1000),
            'unidad' => 'g',
            'caduca_el' => null,
        ];
    }
}
