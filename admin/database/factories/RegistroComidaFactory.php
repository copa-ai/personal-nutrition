<?php

namespace Database\Factories;

use App\Enums\MomentoComida;
use App\Enums\OrigenComida;
use App\Models\Perfil;
use App\Models\RegistroComida;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<RegistroComida> */
class RegistroComidaFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'perfil_id' => Perfil::factory(),
            'fecha' => fake()->date(),
            'momento' => MomentoComida::Comida,
            'origen' => OrigenComida::Casa,
            'descripcion' => fake()->sentence(4),
            'categorias' => [],
            'opcion_ref' => null,
        ];
    }
}
