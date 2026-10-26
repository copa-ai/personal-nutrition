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
            $d = $this->hogar->disponibilidad()->make(['dia_semana' => $dia, 'minutos_comida' => $minutosComida, 'minutos_cena' => $minutosCena]);
            $d->save();
        }
        $this->marta = Perfil::factory()->for($this->hogar)->create(['nombre' => 'Marta', 'restricciones' => ['sin lácteos']]);
        $this->javier = Perfil::factory()->for($this->hogar)->create(['nombre' => 'Javier']);
        $this->menu = Menu::factory()->for($this->hogar)->create(['fecha_inicio' => '2026-11-02']);
    }

    /** Entrega válida para el encargo guardado en $this->menu: 2 opciones por comida y cena, con una adaptación por perfil. */
    protected function entregaValida(): array
    {
        $encargo = $this->menu->encargo;
        $adaptaciones = array_map(fn (array $p): array => ['perfil_id' => $p['id'], 'cambios' => 'Sin cambios.'], $encargo['perfiles']);
        $opcion = fn (string $titulo, int $minutos): array => [
            'titulo' => $titulo, 'categorias' => ['verdura'], 'ingredientes' => [['nombre' => 'tomate', 'cantidad' => 200, 'unidad' => 'g']],
            'duracion_previa_min' => 0, 'duracion_momento_min' => $minutos, 'adaptaciones' => $adaptaciones,
        ];

        $dias = [];
        foreach ($encargo['hogar']['tiempos'] as $t) {
            $dias[] = [
                'fecha' => $t['fecha'],
                'comida' => ['opciones' => $t['minutos_comida'] === null ? [] : [$opcion('Ensalada', $t['minutos_comida']), $opcion('Crema', 10)]],
                'cena' => ['opciones' => $t['minutos_cena'] === null ? [] : [$opcion('Tortilla', 20), $opcion('Pescado', $t['minutos_cena'])]],
            ];
        }

        return ['entrega' => ['completo' => true], 'dias' => $dias];
    }
}
