<?php

namespace App\Services\BaseCompartida;

use Carbon\CarbonImmutable;
use InvalidArgumentException;

/**
 * Regla de supervivencia entre varias bases con el mismo cluster: en cual se escribe y cuales sobran.
 *
 *  1. Se escribe en la que tiene mas eventos en la auditoria (la que conserva mas datos); a igualdad, la de menor identificador
 *     (orden de texto).
 *  2. Histeresis: quien ya escribe en una base la mantiene mientras la mejor no la supere en mas del margen (5 % por defecto), para
 *     que dos bases casi iguales no hagan saltar las escrituras de una a otra.
 *  3. Una base que no es la elegida solo sobra si esta en silencio: ningun evento en los ultimos N minutos (toda escritura se audita).
 *
 * Es una funcion pura de las candidatas: no toca la red ni la base.
 */
final class Eleccion
{
    public function __construct(private readonly float $margen = 0.05)
    {
        if ($margen < 0) {
            throw new InvalidArgumentException('El margen no puede ser negativo.');
        }
    }

    public static function desdeConfig(): self
    {
        return new self((float) config('nutrition.eleccion.margen'));
    }

    /**
     * @param  list<Candidata>  $candidatas  una por base (sin repetir identificador)
     * @param  Candidata|null  $actual  la base en la que este dispositivo escribe ahora, si esta entre las candidatas
     */
    public function elegir(array $candidatas, ?Candidata $actual = null): ?Candidata
    {
        if ($candidatas === []) {
            return null;
        }

        $ordenadas = $candidatas;
        // strcmp y no <=>: PHP compara como numeros las cadenas numericas, y el orden tiene que ser el mismo en todos los dispositivos.
        usort($ordenadas, fn (Candidata $a, Candidata $b): int => $b->eventos <=> $a->eventos ?: strcmp($a->identificador, $b->identificador));
        $mejor = $ordenadas[0];

        if ($actual !== null && $actual->identificador !== $mejor->identificador && $this->dentroDelMargen($actual, $mejor)) {
            foreach ($candidatas as $candidata) {
                if ($candidata->identificador === $actual->identificador) {
                    return $candidata;
                }
            }
        }

        return $mejor;
    }

    /**
     * Las bases que sobran: no son la elegida y estan en silencio.
     *
     * @param  list<Candidata>  $candidatas
     * @return list<Candidata>
     */
    public function sobrantes(array $candidatas, Candidata $elegida, CarbonImmutable $ahora, int $silencioMinutos): array
    {
        return array_values(array_filter(
            $candidatas,
            fn (Candidata $c): bool => $c->identificador !== $elegida->identificador && self::enSilencio($c, $ahora, $silencioMinutos),
        ));
    }

    /** Sin eventos en los ultimos $minutos (o sin ninguno). */
    public static function enSilencio(Candidata $candidata, CarbonImmutable $ahora, int $minutos): bool
    {
        return $candidata->ultimoEvento === null || $candidata->ultimoEvento->lte($ahora->subMinutes($minutos));
    }

    private function dentroDelMargen(Candidata $actual, Candidata $mejor): bool
    {
        return $mejor->eventos <= $actual->eventos * (1 + $this->margen);
    }
}
