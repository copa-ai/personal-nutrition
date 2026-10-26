<?php

namespace Database\Seeders;

use App\Enums\CategoriaComida;
use App\Enums\DiaSemana;
use App\Enums\TipoFeedback;
use App\Models\Alimento;
use App\Models\Hogar;
use Illuminate\Database\Seeder;

/** Datos de ejemplo para desarrollo: un hogar con dos perfiles distintos, tiempos de cocinado y algo de stock. */
class HogarSeeder extends Seeder
{
    public function run(): void
    {
        $hogar = Hogar::query()->create(['nombre' => 'Casa']);

        foreach (DiaSemana::cases() as $dia) {
            $finDeSemana = $dia->value >= 6;
            $hogar->disponibilidad()->create([
                'dia_semana' => $dia, 'minutos_comida' => $finDeSemana ? 60 : 30, 'minutos_cena' => $finDeSemana ? 90 : 45,
            ]);
        }

        $marta = $hogar->perfiles()->create([
            'nombre' => 'Marta', 'objetivo' => 'Bajar de peso de forma gradual, con mucha verdura y proteína magra.',
            'kcal_diarias' => 1700, 'proteina_pct' => 30, 'carbohidratos_pct' => 40, 'grasa_pct' => 30,
            'restricciones' => ['sin lácteos'],
        ]);
        $javier = $hogar->perfiles()->create([
            'nombre' => 'Javier', 'objetivo' => 'Ganar peso con comidas densas en calorías y buena proteína.',
            'kcal_diarias' => 2800, 'proteina_pct' => 25, 'carbohidratos_pct' => 45, 'grasa_pct' => 30,
            'restricciones' => ['controlar azúcares simples'],
        ]);
        $marta->feedback()->create(['tipo' => TipoFeedback::Gusta, 'elemento' => 'pescado azul']);
        $marta->feedback()->create(['tipo' => TipoFeedback::NoGusta, 'elemento' => 'coliflor']);
        $javier->feedback()->create(['tipo' => TipoFeedback::Gusta, 'elemento' => 'legumbres']);

        $stock = [
            ['arroz', CategoriaComida::Cereal, 'g', 1000], ['pasta', CategoriaComida::Cereal, 'g', 500],
            ['lentejas', CategoriaComida::Legumbre, 'g', 500], ['huevos', CategoriaComida::Huevo, 'ud', 10],
            ['pechuga de pollo', CategoriaComida::CarneBlanca, 'g', 600], ['salmón', CategoriaComida::Pescado, 'g', 400],
            ['tomate', CategoriaComida::Verdura, 'g', 800], ['espinacas', CategoriaComida::Verdura, 'g', 300],
            ['yogur natural', CategoriaComida::Lacteo, 'ud', 4], ['aceite de oliva', CategoriaComida::Otro, 'ml', 750],
        ];
        foreach ($stock as [$nombre, $categoria, $unidad, $cantidad]) {
            $alimento = Alimento::query()->create(['nombre' => $nombre, 'categoria' => $categoria, 'unidad' => $unidad]);
            $existencia = $hogar->existencias()->make(['cantidad' => $cantidad, 'unidad' => $unidad]);
            $existencia->alimento_id = $alimento->id;
            $existencia->save();
        }
    }
}
