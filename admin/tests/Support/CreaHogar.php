<?php

namespace Tests\Support;

use App\Enums\DiaSemana;
use App\Models\Hogar;
use App\Models\Menu;
use App\Models\Perfil;

/** Montaje común de las pruebas del dominio: un hogar con tiempos todos los días, dos perfiles y un menú que empieza el lunes 2026-11-02. */
trait CreaHogar
{
    protected Hogar $hogar;

    protected Perfil $marta;

    protected Perfil $javier;

    protected Menu $menu;

    protected function crearHogar(int $minutosComida = 30, int $minutosCena = 45): void
    {
        $this->hogar = Hogar::factory()->create(['nombre' => 'Casa']);
        foreach (DiaSemana::cases() as $dia) {
            $d = $this->hogar->disponibilidad()->make(['dia_semana' => $dia, 'minutos_comida' => $minutosComida, 'minutos_cena' => $minutosCena,
                'minutos_desayuno' => 10, 'minutos_almuerzo' => 10, 'minutos_merienda' => 5]);
            $d->save();
        }
        $this->marta = Perfil::factory()->for($this->hogar)->create(['nombre' => 'Marta', 'restricciones' => ['sin lácteos']]);
        $this->javier = Perfil::factory()->for($this->hogar)->create(['nombre' => 'Javier']);
        $this->menu = Menu::factory()->for($this->hogar)->create(['fecha_inicio' => '2026-11-02']);
    }

    /** Entrega válida (versión 3) para el encargo guardado en $this->menu: categorías por toma, 2 opciones por comida y cena (cada una con 2 platos), tomas individuales y explicaciones por perfil. */
    protected function entregaValida(): array
    {
        $encargo = $this->menu->encargo;
        $receta = fn (string $titulo, int $minutos): array => [
            'titulo' => $titulo, 'ingredientes' => [['nombre' => 'tomate', 'cantidad' => 200, 'unidad' => 'g', 'en_stock' => false]],
            'duracion_previa_min' => 0, 'duracion_momento_min' => $minutos,
        ];
        // Una opción de comida o cena son varios platos: los ingredientes van en cada plato, no en la opción.
        $plato = fn (string $titulo): array => ['titulo' => $titulo, 'ingredientes' => [['nombre' => 'tomate', 'cantidad' => 200, 'unidad' => 'g', 'en_stock' => false]], 'pasos' => ['Cocinar']];
        $opcion = fn (string $titulo, int $minutos): array => array_diff_key($receta($titulo, $minutos), ['ingredientes' => 0])
            + ['categorias' => ['verdura'], 'platos' => [$plato("{$titulo}: primero"), $plato("{$titulo}: segundo")]];
        $toma = fn (string $titulo): array => $receta($titulo, 5) + ['descripcion' => 'Rápido.', 'nota' => null];

        $dias = [];
        foreach ($encargo['hogar']['tiempos'] as $t) {
            $dias[] = [
                'fecha' => $t['fecha'],
                'comida' => $t['minutos_comida'] === null ? ['categorias' => [], 'opciones' => []]
                    : ['categorias' => ['verdura'], 'opciones' => [$opcion('Ensalada', $t['minutos_comida']), $opcion('Crema', 10)]],
                'cena' => $t['minutos_cena'] === null ? ['categorias' => [], 'opciones' => []]
                    : ['categorias' => ['verdura'], 'opciones' => [$opcion('Tortilla', 20), $opcion('Pescado', $t['minutos_cena'])]],
                'individuales' => array_map(fn (array $p): array => [
                    'perfil_id' => $p['id'], 'desayuno' => $toma('Tostada'), 'almuerzo' => $toma('Fruta'), 'merienda' => $toma('Yogur'),
                ], $encargo['perfiles']),
            ];
        }
        $explicaciones = array_map(fn (array $p): array => ['perfil_id' => $p['id'], 'texto' => 'Menú pensado para '.$p['nombre'].'.'], $encargo['perfiles']);

        return ['entrega' => ['completo' => true, 'version' => 3], 'dias' => $dias, 'explicaciones' => $explicaciones];
    }
}
