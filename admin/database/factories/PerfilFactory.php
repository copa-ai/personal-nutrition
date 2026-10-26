<?php

namespace Database\Factories;

use App\Models\Hogar;
use App\Models\Perfil;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Perfil> */
class PerfilFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'hogar_id' => Hogar::factory(),
            'nombre' => fake()->firstName(),
            'activo' => true,
            'objetivo' => 'Mantener el peso con una alimentación variada.',
            'kcal_diarias' => 2000,
            'proteina_pct' => 25,
            'carbohidratos_pct' => 45,
            'grasa_pct' => 30,
            'restricciones' => [],
            'notas' => null,
        ];
    }

    public function inactivo(): static
    {
        return $this->state(['activo' => false]);
    }
}
