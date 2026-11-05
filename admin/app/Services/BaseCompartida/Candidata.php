<?php

namespace App\Services\BaseCompartida;

use Carbon\CarbonImmutable;

/**
 * Una base PostgreSQL de la red compartida tal como la ve la sonda: donde esta, quien es (el mismo en cualquier nodo) y cuanto se
 * ha usado.
 *
 * @property-read string $direccion «host:puerto» con el que se llego a ella
 * @property-read string $identificador `system_identifier` de PostgreSQL: unico por base (cluster) y igual desde cualquier cliente
 * @property-read int $eventos filas de la auditoria
 * @property-read ?CarbonImmutable $ultimoEvento el movimiento mas reciente de la auditoria; null si no hay ninguno
 */
final readonly class Candidata
{
    public function __construct(
        public string $direccion,
        public string $identificador,
        public int $eventos,
        public ?CarbonImmutable $ultimoEvento = null,
    ) {}
}
