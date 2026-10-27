<?php

namespace App\Models\Menu;

use App\Enums\CategoriaComida;
use App\Models\Menu;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

trait ConstruyeEncargo
{
    /**
     * Junta las entradas del sistema en el encargo que lee el agente (sin `formato`, `version`, `encargo_id` ni `salida`:
     * los añade EjecutorAgente al escribir el fichero). Es una copia de ese momento; se guarda en `encargo` y con ella se valida la entrega.
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException si falta algo sin lo que el agente no puede planificar
     */
    public function construirEncargo(): array
    {
        $hogar = $this->hogar()->with('disponibilidad')->firstOrFail();
        $perfiles = $hogar->perfiles()->activos()->with('feedback')->orderBy('id')->get();
        if ($perfiles->isEmpty()) {
            throw new InvalidArgumentException('El hogar no tiene ningún perfil activo.');
        }

        $dias = (int) config('agente.menu.dias');
        $inicio = CarbonImmutable::parse($this->fecha_inicio)->startOfDay();
        $fechas = array_map(fn (int $i): CarbonImmutable => $inicio->addDays($i), range(0, $dias - 1));

        $tiempos = array_map(fn (CarbonImmutable $f): array => [
            'fecha' => $f->toDateString(),
            'dia_semana' => $f->dayOfWeekIso,
            'minutos_comida' => $hogar->minutosParaCocinar($f, 'comida'),
            'minutos_cena' => $hogar->minutosParaCocinar($f, 'cena'),
            'minutos_desayuno' => $hogar->minutosParaCocinar($f, 'desayuno'),
            'minutos_almuerzo' => $hogar->minutosParaCocinar($f, 'almuerzo'),
            'minutos_merienda' => $hogar->minutosParaCocinar($f, 'merienda'),
        ], $fechas);
        if (collect($tiempos)->every(fn (array $t): bool => collect($t)->only(['minutos_comida', 'minutos_cena', 'minutos_desayuno', 'minutos_almuerzo', 'minutos_merienda'])->every(fn ($m): bool => $m === null))) {
            throw new InvalidArgumentException('El hogar no tiene tiempos de cocinado definidos para esa semana.');
        }

        $desde = $inicio->subDays((int) config('agente.menu.historial_dias'))->toDateString();

        return [
            'semana' => ['inicio' => $inicio->toDateString(), 'dias' => $dias, 'fechas' => array_map(fn (CarbonImmutable $f): string => $f->toDateString(), $fechas)],
            'categorias_validas' => array_column(CategoriaComida::cases(), 'value'),
            'opciones_por_comida' => ['min' => (int) config('agente.menu.opciones_min'), 'max' => (int) config('agente.menu.opciones_max')],
            'hogar' => ['nombre' => $hogar->nombre, 'tiempos' => $tiempos],
            'perfiles' => $perfiles->map(fn ($p): array => [
                'id' => $p->id,
                'nombre' => $p->nombre,
                'objetivo' => $p->objetivo,
                'kcal_diarias' => $p->kcal_diarias,
                'macros_pct' => ['proteina' => $p->proteina_pct, 'carbohidratos' => $p->carbohidratos_pct, 'grasa' => $p->grasa_pct],
                'restricciones' => $p->restricciones ?? [],
                'notas' => $p->notas,
                'gusta' => $p->feedback->where('tipo.value', 'gusta')->pluck('elemento')->values()->all(),
                'no_gusta' => $p->feedback->where('tipo.value', 'no_gusta')->pluck('elemento')->values()->all(),
                'historial' => $p->registrosComida()->where('fecha', '>=', $desde)->orderBy('fecha')->orderBy('id')->get()->map(fn ($r): array => [
                    'fecha' => $r->fecha->toDateString(), 'momento' => $r->momento->value, 'origen' => $r->origen->value,
                    'descripcion' => $r->descripcion, 'categorias' => $r->categorias ?? [],
                ])->all(),
            ])->all(),
            'stock' => $hogar->existencias()->with('alimento')->where('cantidad', '>', 0)->get()->map(fn ($x): array => [
                'alimento' => $x->alimento->nombre, 'categoria' => $x->alimento->categoria->value,
                'cantidad' => (float) $x->cantidad, 'unidad' => $x->unidad, 'caduca_el' => $x->caduca_el?->toDateString(),
            ])->all(),
            'calendario_previo' => $this->menusPrevios($hogar->id, $inicio),
        ];
    }

    /**
     * Lo ya sugerido en menús anteriores terminados del hogar (solo títulos), para que el agente no repita.
     *
     * @return list<array{inicio: string, dias: list<array<string, mixed>>}>
     */
    private function menusPrevios(int $hogarId, CarbonImmutable $inicio): array
    {
        return Menu::query()
            ->where('hogar_id', $hogarId)->where('estado', 'terminado')->where('id', '!=', $this->id)
            ->whereDate('fecha_inicio', '<', $inicio->toDateString())
            ->orderByDesc('fecha_inicio')->limit((int) config('agente.menu.menus_previos'))->get()
            ->map(fn (Menu $e): array => [
                'inicio' => $e->fecha_inicio->toDateString(),
                'dias' => collect($e->contenido['dias'] ?? [])->map(fn (array $d): array => [
                    'fecha' => $d['fecha'] ?? null,
                    'comida' => collect($d['comida']['opciones'] ?? [])->pluck('titulo')->all(),
                    'cena' => collect($d['cena']['opciones'] ?? [])->pluck('titulo')->all(),
                ])->all(),
            ])->all();
    }
}
