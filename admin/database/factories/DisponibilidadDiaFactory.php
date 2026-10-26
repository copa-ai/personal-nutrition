<?php

namespace Database\Factories;

use App\Enums\DiaSemana;
use App\Models\DisponibilidadDia;
use App\Models\Hogar;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<DisponibilidadDia> */
class DisponibilidadDiaFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'hogar_id' => Hogar::factory(),
            'dia_semana' => fake()->randomElement(DiaSemana::cases()),
            'minutos_comida' => 30,
            'minutos_cena' => 45,
        ];
    }
}
