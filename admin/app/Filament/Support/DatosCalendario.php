<?php

namespace App\Filament\Support;

use App\Enums\MomentoComida;
use App\Models\Hogar;
use App\Models\Menu;
use App\Models\Perfil;
use App\Models\RegistroComida;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Lo que enseña el calendario de un perfil: lo que comió de verdad (el libro «Lo comido») y lo que le toca según el menú activo del hogar.
 *
 * En cada toma (comida y cena) manda lo comido: si hay registro, es «comido». Si no, la opción elegida del menú activo (con los cambios
 * vigentes) es «programado» desde hoy en adelante y «sin_registro» en los días pasados, para ver qué falta por anotar.
 */
class DatosCalendario
{
    private const TOMAS = ['comida', 'cena'];

    private readonly ?Menu $menu;

    /** @var Collection<string, array<string, mixed>> días del menú activo por fecha */
    private readonly Collection $diasDelMenu;

    public function __construct(Hogar $hogar, private readonly Perfil $perfil, private readonly CarbonImmutable $hoy)
    {
        $this->menu = $hogar->menuActivo;
        $this->menu?->setRelation('hogar', $hogar);
        $this->diasDelMenu = collect($this->menu?->contenidoVigente()['dias'] ?? [])->keyBy('fecha');
    }

    /**
     * Un elemento por día entre las dos fechas, ambas incluidas.
     *
     * @return list<array{fecha: string, dia: int, mes: int, hoy: bool, tomas: array<string, ?array<string, mixed>>, otros: list<array{titulo: string, origen: string}>, individuales: list<array{etiqueta: string, titulo: string}>}>
     */
    public function dias(CarbonImmutable $desde, CarbonImmutable $hasta): array
    {
        $registros = RegistroComida::query()
            ->where('perfil_id', $this->perfil->id)
            ->whereDate('fecha', '>=', $desde->toDateString())
            ->whereDate('fecha', '<=', $hasta->toDateString())
            ->orderBy('id')
            ->get()
            ->groupBy(fn (RegistroComida $r): string => $r->fecha->toDateString());

        $dias = [];
        for ($fecha = $desde; $fecha <= $hasta; $fecha = $fecha->addDay()) {
            $clave = $fecha->toDateString();
            /** @var Collection<int, RegistroComida> $delDia */
            $delDia = $registros->get($clave, collect());
            $dias[] = [
                'fecha' => $clave,
                'dia' => $fecha->day,
                'mes' => $fecha->month,
                'hoy' => $fecha->equalTo($this->hoy),
                'tomas' => array_combine(self::TOMAS, array_map(fn (string $momento): ?array => $this->toma($clave, $momento, $delDia), self::TOMAS)),
                'otros' => $delDia->filter(fn (RegistroComida $r): bool => $r->momento === MomentoComida::Otro)
                    ->map(fn (RegistroComida $r): array => ['titulo' => $r->descripcion, 'origen' => $r->origen->getLabel()])->values()->all(),
                'individuales' => $this->individuales($clave),
            ];
        }

        return $dias;
    }

    /**
     * Comido (con el registro), programado o sin registrar; null si no hay nada. Las programadas llevan las `opciones` (títulos) y cuál es la `elegida`.
     *
     * @param  Collection<int, RegistroComida>  $delDia
     * @return ?array{tipo: string, titulo: string, origen?: string, opciones?: list<string>, elegida?: int}
     */
    private function toma(string $fecha, string $momento, Collection $delDia): ?array
    {
        $registro = $delDia->first(fn (RegistroComida $r): bool => $r->momento->value === $momento);
        if ($registro !== null) {
            return ['tipo' => 'comido', 'titulo' => $registro->descripcion, 'origen' => $registro->origen->getLabel()];
        }

        $opcion = $this->menu?->opcionElegida($fecha, $momento);
        if ($opcion === null) {
            return null;
        }

        return [
            'tipo' => $fecha >= $this->hoy->toDateString() ? 'programado' : 'sin_registro',
            'titulo' => (string) ($opcion['titulo'] ?? ''),
            'opciones' => array_map(fn (array $o): string => (string) ($o['titulo'] ?? ''), $this->menu->opcionesDe($fecha, $momento)),
            'elegida' => $this->menu->indiceElegido($fecha, $momento),
        ];
    }

    /**
     * Desayuno, almuerzo y merienda que el menú propone a este perfil. Solo de hoy en adelante: en los días pasados lo que cuenta es lo comido.
     *
     * @return list<array{etiqueta: string, titulo: string}>
     */
    private function individuales(string $fecha): array
    {
        $dia = $this->diasDelMenu->get($fecha);
        if ($dia === null || $fecha < $this->hoy->toDateString()) {
            return [];
        }
        $fila = collect($this->menu->individualesDelDia($dia))->firstWhere('perfil_id', $this->perfil->id);

        return array_values(array_map(
            fn (array $t): array => ['etiqueta' => $t['etiqueta'], 'titulo' => (string) ($t['toma']['titulo'] ?? '')],
            $fila['tomas'] ?? [],
        ));
    }
}
