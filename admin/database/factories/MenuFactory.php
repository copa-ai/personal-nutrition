<?php

namespace Database\Factories;

use App\Models\Hogar;
use App\Models\Menu;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Menu> */
class MenuFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'nombre' => 'Menú de la semana',
            'hogar_id' => Hogar::factory(),
            'fecha_inicio' => '2026-11-02',
        ];
    }
}
