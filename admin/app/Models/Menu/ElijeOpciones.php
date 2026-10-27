<?php

namespace App\Models\Menu;

use App\Enums\DiaSemana;
use Carbon\CarbonImmutable;
use InvalidArgumentException;

trait ElijeOpciones
{
    private const MOMENTOS = ['comida', 'cena'];

    /** Tomas individuales por perfil: no tienen opciones, solo una propuesta. */
    private const INDIVIDUALES = ['desayuno' => 'Desayuno', 'almuerzo' => 'Almuerzo', 'merienda' => 'Merienda'];

    /**
     * Opciones de una toma del menú importado. Vacío si el hogar no cocina ese momento o el día no existe.
     *
     * @return list<array<string, mixed>>
     */
    public function opcionesDe(string $fecha, string $momento): array
    {
        $dia = collect($this->contenido['dias'] ?? [])->firstWhere('fecha', $fecha);

        return array_values($dia[$momento]['opciones'] ?? []);
    }

    /** Índice (desde 0) de la opción elegida en una toma. Sin elección hecha, o si ya no es válida, es la primera. */
    public function indiceElegido(string $fecha, string $momento): int
    {
        $indice = $this->selecciones["{$fecha}/{$momento}"] ?? 0;

        return is_int($indice) && $indice >= 0 && $indice < count($this->opcionesDe($fecha, $momento)) ? $indice : 0;
    }

    /** @return ?array<string, mixed> la opción elegida, o null si en esa toma no se cocina */
    public function opcionElegida(string $fecha, string $momento): ?array
    {
        return $this->opcionesDe($fecha, $momento)[$this->indiceElegido($fecha, $momento)] ?? null;
    }

    /**
     * Guarda qué opción se prefiere en una toma. El PDF se genera con estas elecciones.
     *
     * @throws InvalidArgumentException si la toma o la opción no existen
     */
    public function elegirOpcion(string $fecha, string $momento, int $indice): void
    {
        $opciones = in_array($momento, self::MOMENTOS, true) ? $this->opcionesDe($fecha, $momento) : [];
        if ($indice < 0 || $indice >= count($opciones)) {
            throw new InvalidArgumentException("La opción {$indice} no existe en esa toma ({$fecha} · {$momento}).");
        }

        $this->update(['selecciones' => ["{$fecha}/{$momento}" => $indice] + ($this->selecciones ?? [])]);
    }

    /** Categorías de alimento comunes a todas las opciones de una toma (comida o cena). Vacío si no se cocina. */
    public function categoriasDe(string $fecha, string $momento): array
    {
        $dia = collect($this->contenido['dias'] ?? [])->firstWhere('fecha', $fecha);

        return array_values($dia[$momento]['categorias'] ?? []);
    }

    /**
     * Desayuno, almuerzo y merienda de cada perfil en un día (las tomas individuales). Sin elección: es una sola propuesta por toma.
     *
     * @param  array<string, mixed>  $dia  un día de `contenido['dias']`
     * @return list<array{perfil_id: mixed, nombre: string, tomas: array<string, array{etiqueta: string, toma: array<string, mixed>}>}>
     */
    public function individualesDelDia(array $dia): array
    {
        $nombres = collect($this->encargo['perfiles'] ?? [])->pluck('nombre', 'id');
        $filas = [];
        foreach ($dia['individuales'] ?? [] as $ind) {
            $tomas = [];
            foreach (self::INDIVIDUALES as $momento => $etiqueta) {
                if (! empty($ind[$momento])) {
                    $tomas[$momento] = ['etiqueta' => $etiqueta, 'toma' => $ind[$momento]];
                }
            }
            $filas[] = ['perfil_id' => $ind['perfil_id'] ?? null, 'nombre' => (string) ($nombres[$ind['perfil_id'] ?? null] ?? 'Perfil '.($ind['perfil_id'] ?? '?')), 'tomas' => $tomas];
        }

        return $filas;
    }

    /**
     * Explicación del menú por perfil (por qué se ha planteado así: gimnasio, horarios…).
     *
     * @return list<array{perfil_id: mixed, nombre: string, texto: string}>
     */
    public function explicaciones(): array
    {
        $nombres = collect($this->encargo['perfiles'] ?? [])->pluck('nombre', 'id');

        return collect($this->contenido['explicaciones'] ?? [])
            ->filter(fn (mixed $e): bool => is_array($e) && trim((string) ($e['texto'] ?? '')) !== '')
            ->map(fn (array $e): array => [
                'perfil_id' => $e['perfil_id'] ?? null,
                'nombre' => (string) ($nombres[$e['perfil_id'] ?? null] ?? 'Perfil '.($e['perfil_id'] ?? '?')),
                'texto' => trim((string) $e['texto']),
            ])->values()->all();
    }

    /** Minutos que el hogar tiene para cocinar en una toma, según el encargo con el que se generó el menú. Null = ese momento no se cocina. */
    public function minutosDelHogar(string $fecha, string $momento): ?int
    {
        return collect($this->encargo['hogar']['tiempos'] ?? [])->firstWhere('fecha', $fecha)["minutos_{$momento}"] ?? null;
    }

    /**
     * El menú organizado para mostrarlo: por día, con cada toma, sus opciones y cuál está elegida. Cada opción lleva además
     * `por_comprar`: cuántos de sus ingredientes no están en casa (o no se sabe), un dato útil para decidir entre opciones.
     *
     * Cada día lleva además `individuales` (desayuno, almuerzo y merienda por perfil, sin elección) y cada toma sus `categorias`.
     *
     * @return list<array{fecha: string, titulo: string, corto: string, inicial: string, individuales: list<array<string, mixed>>, tomas: array<string, array{etiqueta: string, minutos: ?int, categorias: list<string>, elegida: int, hay_eleccion: bool, opciones: list<array<string, mixed>>}>}>
     */
    public function tomas(): array
    {
        $dias = [];
        foreach ($this->contenido['dias'] ?? [] as $dia) {
            $fecha = CarbonImmutable::parse($dia['fecha'])->locale('es');
            $tomas = [];
            foreach (['comida' => 'Comida', 'cena' => 'Cena'] as $momento => $etiqueta) {
                $opciones = array_map(fn (array $o): array => $o + [
                    'por_comprar' => collect($o['ingredientes'] ?? [])->filter(fn (array $i): bool => ($i['en_stock'] ?? false) !== true)->count(),
                ], $this->opcionesDe($dia['fecha'], $momento));
                $tomas[$momento] = [
                    'etiqueta' => $etiqueta,
                    'minutos' => $this->minutosDelHogar($dia['fecha'], $momento),
                    'categorias' => $this->categoriasDe($dia['fecha'], $momento),
                    'elegida' => $this->indiceElegido($dia['fecha'], $momento),
                    'hay_eleccion' => isset($this->selecciones["{$dia['fecha']}/{$momento}"]) && $this->eleccionValida("{$dia['fecha']}/{$momento}", $this->selecciones["{$dia['fecha']}/{$momento}"]),
                    'opciones' => $opciones,
                ];
            }
            $dias[] = [
                'fecha' => $dia['fecha'],
                'titulo' => ucfirst($fecha->isoFormat('dddd D [de] MMMM')),
                'corto' => ucfirst($fecha->isoFormat('ddd D')),
                'inicial' => DiaSemana::deFecha($fecha)->inicial(),
                'individuales' => $this->individualesDelDia($dia),
                'tomas' => $tomas,
            ];
        }

        return $dias;
    }

    /** Tomas elegibles: las de comida y cena en las que el hogar cocina (las que tienen opciones). Las individuales no se cuentan: no se eligen. */
    public function totalTomas(): int
    {
        return collect($this->contenido['dias'] ?? [])->sum(
            fn (array $d): int => (int) (count($d['comida']['opciones'] ?? []) > 0) + (int) (count($d['cena']['opciones'] ?? []) > 0)
        );
    }

    /** Tomas con una elección hecha a propósito (el resto usa la primera opción). */
    public function totalElegidas(): int
    {
        return collect($this->selecciones ?? [])->filter(fn (mixed $v, string $clave): bool => $this->eleccionValida($clave, $v))->count();
    }

    private function eleccionValida(string $clave, mixed $indice): bool
    {
        [$fecha, $momento] = array_pad(explode('/', $clave, 2), 2, '');

        return is_int($indice) && $indice >= 0 && $indice < count($this->opcionesDe($fecha, $momento));
    }
}
